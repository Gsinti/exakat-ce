<?php declare(strict_types = 1);
/*
 * Copyright 2012-2022 Damien Seguy – Exakat SAS <contact(at)exakat.io>
 * This file is part of Exakat.
 *
 * Exakat is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Exakat is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Exakat.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://exakat.io/>.
 *
*/


namespace Exakat\Loader;

use Exakat\Data\Collector;
use Exakat\Tasks\Helpers\Atom;
use Exakat\Helpers\Timer;
use stdClass;

class SplitGraphson extends Loader {
    private const CSV_SEPARATOR = ',';
    private const LOAD_CHUNK      = 10000;
    private $load_chunk = self::LOAD_CHUNK;
    private const LOAD_CHUNK_LINK = 200000;
    private const LOAD_CHUNK_PROPERTY = 10000;

    private $tokenCounts   = array('Project' => 1);

    private $config = null;

    private $id        = 1;

    private $graphdb         = null;

    private $path            = '';
    private $pathLink        = '';
    private $pathProperties  = '';
    private $pathDef         = '';

    private $total           = 0;
    private $totalLink       = 0;
    private $totalProperties = 0;

    private $dictCode = null;

    private $sqlite   = null;

    private $log = null;

    public function __construct(\Sqlite3 $sqlite, Atom $id0) {
        $this->config         = exakat('config');
        $this->graphdb        = exakat('graphdb');

        $this->sqlite3        = $sqlite;
        $this->path           = "{$this->config->tmp_dir}/graphdb.graphson";
        $this->pathLink       = "{$this->config->tmp_dir}/graphdb.link.graphson";
        $this->pathProperties = "{$this->config->tmp_dir}/graphdb.properties.graphson";
        $this->pathDef        = "{$this->config->tmp_dir}/graphdb.def";

        $this->dictCode  = new Collector();

        $this->log = fopen($this->config->log_dir . '/loader.timing.csv', 'w+');

        $this->cleanCsv();

        $jsonText = json_encode($id0->toGraphsonLine($this->id)) . PHP_EOL;
        assert(!json_last_error(), 'Error encoding ' . $id0->atom . ' : ' . json_last_error_msg());

        file_put_contents($this->path, $jsonText, \FILE_APPEND);

        ++$this->total;
    }

    public function __destruct() {
        $this->cleanCsv();
    }

    public function finalize(array $relicat): bool {
        if ($this->total !== 0) {
            $this->saveNodes();
        }

        display("Init finalize\n");

        $totalDuration = new Timer();

        $this->saveNodeLinks();
        $this->saveProperties();

        $query = 'g.V().hasLabel("Project").id();';
        $res = $this->graphdb->query($query);
        $project_id = $res->toUuid();

        // @todo : move this to a Graph class method (it knows the project_id)
        $query = 'g.V().hasLabel("File").not(where( __.in("PROJECT"))).addE("PROJECT").from(__.V(' . $project_id . '));';
        $this->graphdb->query($query);

        $query = 'g.V().hasLabel("Virtualglobal").not(where( __.in("GLOBAL"))).addE("GLOBAL").from(__.V(' . $project_id . '));';
        $this->graphdb->query($query);

        $f = fopen('php://memory', 'r+');
        $total = 0;
        $chunk = 0;

        foreach($relicat as $row) {
            fputcsv($f, $row);
            ++$total;
            ++$chunk;
        }
        if ($chunk > $this->load_chunk) {
            $f = $this->saveLinks($f);
            $chunk = 0;
        }

        // global variables to global variabldefintiion
        $query = <<<'GREMLIN'
g.V().hasLabel("Virtualglobal").as('id').as('code').select('id', 'code').by(id).by('code');
GREMLIN;
        $res = $this->graphdb->query($query);
        $vg = array();
        foreach($res->toArray() as $atom) {
            $vg[$atom['code']] = $atom['id'];
        }

        // global variables to variabldefinition
        $query = <<<'GREMLIN'
g.V().hasLabel("File").out("DEFINITION").hasLabel("Variabledefinition").as('id').as('code').select('id', 'code').by(id).by('code');
GREMLIN;
       $res = $this->graphdb->query($query);
       $ids = array();
       $total = 0;
       foreach($res->toArray() as $atom) {
           if (!isset($vg[$atom['code']])) {
               continue;
           }

           if (isset($ids[$vg[$atom['code']]])) {
               $ids[$vg[$atom['code']]][] = array($vg[$atom['code']], $atom['id']);
           } else {
               $ids[$vg[$atom['code']]] = array(array($vg[$atom['code']], $atom['id']));
           }
           ++$total;
       }

       // global variables to variabldefinition
       $query = <<<'GREMLIN'
g.V().hasLabel("Global").out("GLOBAL").hasLabel("Variabledefinition").as('id').as('code').select('id', 'code').by(id).by('code');
GREMLIN;
        $res = $this->graphdb->query($query);
        foreach($res->toArray() as $atom) {
            if (!isset($vg[$atom['code']])) {
                continue;
            }

            if (isset($ids[$vg[$atom['code']]])) {
                $ids[$vg[$atom['code']]][] = array($vg[$atom['code']], $atom['id']);
            } else {
                $ids[$vg[$atom['code']]] = array(array($vg[$atom['code']], $atom['id']));
            }
            ++$total;
        }

        $timer = new Timer();
        $query = <<<'GREMLIN'
globalVars.each { y ->
    g.V(y[0]).addE('DEFINITION').to(g.V(y[1])).iterate();
}
GREMLIN;
        $this->graphdb->query($query, array('globalVars' => array_merge(...$ids) ) );
        $timer->end();

        $this->log("globals\t$total\t" . $timer->duration());

        // $GLOBALS
        $query = <<<'GREMLIN'
g.V().hasLabel("Phpvariable").has("fullcode", "\$GLOBALS").as("a")
 .V().hasLabel("Virtualglobal").as("b").as("c")
 .select("a","b").by("code")
 .where("a", eq("b")).select("c").addE("DEFINITION").to("a").count()
GREMLIN;
        $this->graphdb->query($query);
        $res = $this->graphdb->query($query);

        // global variables to $GLOBALS['x']
        $query = <<<'GREMLIN'
g.V().hasLabel("Phpvariable").has("fullcode", "\$GLOBALS").in("VARIABLE").hasLabel("Array").has("globalvar").not(where(__.in("DEFINITION"))).as("a")
 .V().hasLabel("Virtualglobal").as("b").as("c")
 .select("a","b").by("globalvar").by("code")
 .where("a", eq("b")).select("c").addE("DEFINITION").to("a").count()
GREMLIN;
        $res = $this->graphdb->query($query);
        $res->toInt() . " \$GLOBALS['d']\n";

        $definitionSQL = <<<'SQL'
SELECT DISTINCT CASE WHEN definitions.id IS NULL THEN definitions2.id ELSE definitions.id END AS definition, GROUP_CONCAT(DISTINCT calls.id) AS call, count(calls.id) AS id
FROM calls
LEFT JOIN definitions 
    ON definitions.type       = calls.type       AND
       definitions.fullnspath = calls.fullnspath
LEFT JOIN definitions definitions2
    ON definitions2.type       = calls.type       AND
       definitions2.fullnspath = calls.globalpath 
WHERE (definitions.id IS NOT NULL OR definitions2.id IS NOT NULL) AND
        CASE WHEN definitions.id IS NULL THEN definitions2.id ELSE definitions.id END != calls.id
GROUP BY definition
SQL;
        $res = $this->sqlite3->query($definitionSQL);
        // Fast dump, with a write to memory first
        while($row = $res->fetchArray(\SQLITE3_NUM)) {
            // Skip reflexive definitions, which never exist.
            if ($row[0] === $row[1]) { continue; }
            $total += $row[2];
            $chunk += $row[2];
            unset($row[2]);
            $row[0] = $this->graphdb->fixId($row[0]);
            $r = explode(',', $row[1]);
            $r = array_map(array($this->graphdb, 'fixId'), $r);
            $row[1] = implode('-', $r);
            fputcsv($f, $row);

            if ($chunk > $this->load_chunk) {
                $f = $this->saveLinks($f);
                $chunk = 0;
            }
        }

        if (empty($total)) {
            display('no definitions');
        } else {
            display("loading $total definitions");
            $this->saveLinks($f);
            display("loaded $total definitions");
        }

        $this->saveTokenCounts();

        $totalDuration->end();

        display('loaded nodes (duration : ' . $totalDuration->duration(Timer::MS) . ' ms)');

        $this->cleanCsv();
        display('Cleaning CSV');

        return true;
    }

    private function saveProperties() {
        if (!file_exists($this->pathProperties)) {
            return;
        }

        if ($this->totalProperties === 0) {
            $this->log("properties\t0");

            return;
        }

        // break down property files into small chunks for processing inside 300s.
        $fp = fopen($this->pathProperties, 'r');
        $fp2 = fopen($this->pathProperties . '.tmp', 'w+');
        $j = 0;
        for($i = 0; $i < $this->totalProperties; ++$i) {
            ++$j;
            fwrite($fp2, fgets($fp));
            if ($j >= self::LOAD_CHUNK_PROPERTY) {
                $this->savePropertiesGremlin(intdiv($i, self::LOAD_CHUNK_PROPERTY));
                fclose($fp2);
                $fp2 = fopen($this->pathProperties . '.tmp', 'w+');
                $j = 0;
            }
        }
        fclose($fp);
        fclose($fp2);
        $this->savePropertiesGremlin(intdiv($i, self::LOAD_CHUNK_PROPERTY));

        unlink($this->pathProperties);
        unlink($this->pathProperties . '.tmp');
    }

    private function savePropertiesGremlin(int $id): void {
        $timer = new Timer();
        $query = <<<GREMLIN
new File('{$this->pathProperties}.tmp').eachLine {
    (property, targets) = it.split('-');
    vertices = targets.split(',');

    g.V(vertices).property(property, true).iterate();
}

g.V().has('intval', 0).not(has("boolean", true)).property('boolean', false).iterate();

GREMLIN;
        $this->graphdb->query($query);
        $timer->end();

        $this->log("properties\t$id\t" . $timer->duration());
    }

    private function saveLinks($f) {
        rewind($f);
        $fp = fopen($this->pathDef, 'w+');
        $length = fwrite($fp, stream_get_contents($f));
        fclose($fp);
        fclose($f);

        if ($length > 0) {
            $timer = new Timer();
            $query = <<<GREMLIN
new File('$this->pathDef').eachLine {
    (fromVertex, target) = it.split(',')

    toVertices = target.split('-');

    g.V(toVertices).addE('DEFINITION').from(V(fromVertex)).iterate();
}

GREMLIN;
            $this->graphdb->query($query);
            $timer->end();

            $this->log("links finalize\t" . $timer->duration());
        }

        return fopen('php://memory', 'r+');
    }

    private function cleanCsv(): void {
        if (file_exists($this->path)) {
            unlink($this->path);
        }

        if (file_exists($this->pathLink)) {
            unlink($this->pathLink);
        }

        if (file_exists($this->pathProperties)) {
            unlink($this->pathProperties);
        }

        if (file_exists($this->pathDef)) {
            unlink($this->pathDef);
        }
    }

    private function saveTokenCounts(): void {
        $datastore = exakat('datastore');

        $datastore->addRow('tokenCounts', $this->tokenCounts);
    }

    public function saveFiles(string $exakatDir, array $atoms, array $links): void {
        $fileName = 'unknown';

        $json     = array();
        $properties = array('noscream'     => array(),
                            'reference'    => array(),
                            'variadic'     => array(),
                            'heredoc'      => array(),
                            'flexible'     => array(),
                            'constant'     => array(),
                            'enclosing'    => array(),
                            'final'        => array(),
                            'boolean'      => array(),
                            'bracket'      => array(),
                            'close_tag'    => array(),
                            'trailing'     => array(),
                            'alternative'  => array(),
                            'absolute'     => array(),
                            'abstract'     => array(),
                            'readonly'     => array(),
                            'aliased'      => array(),
                            'isRead'       => array(),
                            'isModified'   => array(),
                            'static'       => array(),
                            'isNull'       => array(),
                            );
        foreach($atoms as $atom) {
            if ($atom->atom === 'File') {
                $fileName = $atom->code;
            }
            $json[$atom->id] = $atom->toGraphsonLine($this->id);
            $fixedId = $this->graphdb->fixId($atom->id);
            foreach($atom->boolProperties() as $property) {
                $properties[$property][] = $fixedId;
            }
        }

        foreach($links as &$link) {
            $this->tokenCounts[$link[0]] = ($this->tokenCounts[$link[0]] ?? 0) + 1;

            $link[1] = $this->graphdb->fixId($link[1]);
            $link[2] = $this->graphdb->fixId($link[2]);
            $link = implode('-', $link);
        }
        unset($link);

        $total = 0; // local total
        $append = array();
        foreach($json as $j) {
            $V = $j->properties['code'][0]->value;
            $j->properties['code'][0]->value = $this->dictCode->get($V);

            if (isset($j->properties['lccode'][0]->value)) {
                $j->properties['lccode'][0]->value = $this->dictCode->get($j->properties['lccode'][0]->value);
            }

            if (isset($j->properties['propertyname']) ) {
                $j->properties['propertyname'][0]->value = $this->dictCode->get($j->properties['propertyname'][0]->value);
            }

            if (isset($j->properties['globalvar']) ) {
                $j->properties['globalvar'][0]->value = $this->dictCode->get($j->properties['globalvar'][0]->value);
            }

            $X = $this->json_encode($j);
            assert(!json_last_error(), $fileName . ' : error encoding normal ' . $j->label . ' : ' . json_last_error_msg() . "\n" . print_r($j, true));
            $append[] = $X;

            if (isset($this->tokenCounts[$j->label])) {
                ++$this->tokenCounts[$j->label];
            } else {
                $this->tokenCounts[$j->label] = 1;
            }
            ++$this->total;

            if ($this->total > $this->load_chunk) {
                file_put_contents($this->path, implode(PHP_EOL, $append) . PHP_EOL, \FILE_APPEND);
                $this->saveNodes();
                $append = array();
            }

            ++$total;
        }

        if (!empty($append)) {
            file_put_contents($this->path, implode(PHP_EOL, $append) . PHP_EOL, \FILE_APPEND);
        }
        $this->totalLink += count($links);
        file_put_contents($this->pathLink, implode(PHP_EOL, $links) . PHP_EOL, \FILE_APPEND);
        foreach($properties as $property => $targets) {
            if (!empty($targets)) {
                $chunks = array_chunk($targets, self::LOAD_CHUNK_PROPERTY);
                foreach($chunks as $chunk) {
                    ++$this->totalProperties;
                    file_put_contents($this->pathProperties, $property . '-' . implode(',', $chunk) . PHP_EOL, \FILE_APPEND);
                }
            }
        }

        if ($this->total > $this->load_chunk) {
            $this->saveNodes();
        }

        $datastore = exakat('datastore');
        $datastore->addRow('dictionary', $this->dictCode->getRecent());
    }

    private function saveNodes(): void {
        $size = filesize($this->path);
        if ($size === 0) {
            return;
        }

        $timer = new Timer();
        $this->graphdb->query("graph.io(IoCore.graphson()).readGraph(\"$this->path\");");
        unlink($this->path);
        $timer->end();
        $this->log("path\t{$this->total}\t$size\t" . $timer->duration());

        $this->total = 0;
    }

    private function saveNodeLinks(): void {
        if (!file_exists($this->pathLink)) {
            return;
        }

        if ($this->totalLink === 0) {
            return ;
        }

        // break down property files into small chunks for processing inside 300s.
        $fp = fopen($this->pathLink, 'r');
        $fp2 = fopen($this->pathLink . '.tmp', 'w+');
        $j = 0;
        for($i = 0; $i < $this->totalLink; ++$i) {
            ++$j;
            fwrite($fp2, fgets($fp));
            if ($j >= self::LOAD_CHUNK_LINK) {
                $this->saveLinkGremlin(intdiv($i, self::LOAD_CHUNK_LINK));
                fclose($fp2);
                $fp2 = fopen($this->pathLink . '.tmp', 'w+');
                $j = 0;
            }
        }
        fclose($fp);
        fclose($fp2);
        $this->saveLinkGremlin(intdiv($i, self::LOAD_CHUNK_LINK));

        unlink($this->pathLink);
        unlink($this->pathLink . '.tmp');
    }

    private function saveLinkGremlin(int $id): void {
        $timer = new Timer();

        $query = <<<GREMLIN
new File('{$this->pathLink}.tmp').eachLine {
    (theLabel, fromVertex, toVertex) = it.split('-');

    g.V(fromVertex).addE(theLabel).to(V(toVertex)).iterate();
}

GREMLIN;
        $this->graphdb->query($query);
        $timer->end();

        $this->log("links\t$id\t" . $timer->duration());
    }

    private function json_encode(Stdclass $object): string {
        // in case the function name is full of non-encodable characters.
        if (isset($object->properties['fullnspath']) && !mb_check_encoding($object->properties['fullnspath'][0]->value, 'UTF-8')) {
            $object->properties['fullnspath'][0]->value = utf8_encode($object->properties['fullnspath'][0]->value);
        }
        if (isset($object->properties['propertyname']) && !mb_check_encoding((string) $object->properties['propertyname'][0]->value, 'UTF-8')) {
            $object->properties['propertyname'][0]->value = utf8_encode($object->properties['propertyname'][0]->value);
        }
        if (isset($object->properties['fullcode']) && !mb_check_encoding((string) $object->properties['fullcode'][0]->value, 'UTF-8')) {
            $object->properties['fullcode'][0]->value = utf8_encode($object->properties['fullcode'][0]->value);
        }
        if (isset($object->properties['code']) && !mb_check_encoding((string) $object->properties['code'][0]->value, 'UTF-8')) {
            $object->properties['code'][0]->value = utf8_encode($object->properties['code'][0]->value);
        }
        if (isset($object->properties['noDelimiter']) && !mb_check_encoding((string) $object->properties['noDelimiter'][0]->value, 'UTF-8')) {
            $object->properties['noDelimiter'][0]->value = utf8_encode($object->properties['noDelimiter'][0]->value);
        }
        if (isset($object->properties['delimiter']) && !mb_check_encoding((string) $object->properties['delimiter'][0]->value, 'UTF-8')) {
            $object->properties['delimiter'][0]->value = utf8_encode($object->properties['delimiter'][0]->value);
        }
        if (isset($object->properties['globalvar']) && !mb_check_encoding((string) $object->properties['globalvar'][0]->value, 'UTF-8')) {
            $object->properties['globalvar'][0]->value = utf8_encode($object->properties['globalvar'][0]->value);
        }
        if (isset($object->properties['ws']) && !mb_check_encoding((string) $object->properties['ws'][0]->value, 'UTF-8')) {
            $object->properties['ws'][0]->value = utf8_encode($object->properties['ws'][0]->value);
        }

        return json_encode($object);
    }

    private function log(string $message): void {
        fwrite($this->log, $message . PHP_EOL);
    }
}

?>
