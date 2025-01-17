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


namespace Exakat\Analyzer\Classes;

use Exakat\Analyzer\Analyzer;

class ConstantClass extends Analyzer {
    public function analyze(): void {
        // class x { const yx = 2;}
        $this->atomIs('Class')
             ->isNot('abstract', true)
             ->not(
                $this->side()
                     ->filter(
                        $this->side()
                             ->outIs(array('METHOD', 'MAGICMETHOD', 'PPP'))
                     )
             )
             ->hasOut('CONST');
        $this->prepareQuery();

        // interface x { const yx = 2;}
        $this->atomIs('Interface')
             ->not(
                $this->side()
                     ->filter(
                        $this->side()
                             ->outIs(array('METHOD', 'MAGICMETHOD', 'PPP'))
                     )
             )
             ->hasOut('CONST');
        $this->prepareQuery();
    }
}

?>
