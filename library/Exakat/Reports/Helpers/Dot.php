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

namespace Exakat\Reports\Helpers;

use Exakat\Exakat;

class Dot {
    private $links   = array();
    private $nodes   = array();
    private $options = array();

    public function addNode(string $label, array $options = array()): int {
        $node = array_merge(compact('label'), $options);
        $this->nodes[] = $node;

        return count($this->nodes) - 1;
    }

    public function addLink(int $o, int $d): void {
        if (!isset($this->links[$o])) {
            $this->links[$o] = array();
        }
        $this->links[$o][$d] = $d;
    }

    public function setOptions(string $what, string $name, string $value): void {
        $this->options[$what][$name] = $value;
    }

    public function __toString() {
        $atoms = array();

        foreach($this->nodes as $id => $label) {
            $options = $this->toStyle(array_merge($this->options['nodes'], $label));

            $atoms[] = "$id [$options]";
        }
        $atoms = implode(PHP_EOL, $atoms);

        $links = array();
        foreach($this->links as $origin => $destinations) {
            foreach($destinations as $destination) {
                $links[] = $origin . ' -> ' . $destination;
            }
        }
        $links = implode(PHP_EOL, $links);

        $date = date('r');
        $version = Exakat::VERSION;

        $options = 'graph [' . $this->toStyle($this->options['graph'] ?? array()) . '];' . PHP_EOL .
                   'node [' . $this->toStyle($this->options['node'] ?? array()) . '];' . PHP_EOL .
                   'edge [' . $this->toStyle($this->options['link'] ?? array()) . '];' . PHP_EOL;

        $r = "digraph graphname {
/* This file was generated by Exakat $version.
   date : $date
   http://www.exakat.io/  
*/

$options

$atoms

$links
}";

        return $r;
    }

    private function toStyle(array $array = array()): string {
        $return = array();
        foreach($array as $name => $value) {
            $value = addslashes((string) $value);
            $return[] = "$name=\"$value\"";
        }

        return implode(' ', $return);
    }
}

?>