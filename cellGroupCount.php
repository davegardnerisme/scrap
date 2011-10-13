<?php
/**
 * Cell group counting
 * 
 * To do with patterns with connecting squares -- count the distinct groups.
 * Cells only connect horizontally and vertically.
 * 
 * A co-worker challenge! Turns out this is pretty much
 * http://en.wikipedia.org/wiki/Flood_fill
 * 
 * The recursive algorithm is much slower in PHP than the stack-based one and
 * also suffers from a maximum recursion depth limit (for big boards).
 * 
 * We invent a random board of size x by y and then work out how many connected
 * groups of cells.
 * 
 * Params:
 * 
 *   php cellGroupCount.php <x> <y> <density%>
 * 
 * Where density is the % occupancy you want for the cells.
 * 
 * @author Dave <dave@davegardner.me.uk>
 */

$x = isset($argv[1]) ? $argv[1] : 10;
$y = isset($argv[2]) ? $argv[2] : $x;
$density = isset($argv[3]) ? $argv[3] : 60;

// ---

$pattern = randomPattern($x, $y, $density);

$s = microtime(TRUE);
$remaining = parsePattern($pattern);
$e = microtime(TRUE);
$parseTime = ceil(($e - $s) *1000);

echo "\n" . showPattern($remaining, $x, $y) . "\n";

// ---

// this is the real algorithm:

$s = microtime(TRUE);
$groups = 0;
while (!empty($remaining)) {
    
    // k-shift next entry from remaining list; this is our start point
    reset($remaining);
    list($current) = each($remaining);
    
    // remove all elements connected from this start point from our list
//    $remaining = removeConnectedRecursive($remaining, $current);
    $remaining = removeConnectedStack($remaining, $current);
    
    // we have found another group!
    $groups++;
}
$e = microtime(TRUE);
$calcTime = ceil(($e - $s) *1000);

// ---

echo "#groups: $groups\nParsed in {$parseTime}ms, Calculated in {$calcTime}ms\n";


/******************************************************************************/

/**
 * Make a random pattern
 * 
 * @param integer $x How many cols
 * @param integer|NULL $y How many rows, or NULL for square
 * @param integer $density How dense to make the board; as %
 * 
 * @return string
 */
function randomPattern($x, $y = NULL, $density = 60)
{
    if ($y === NULL) {
        $y = $x;
    }
    $p = '';
    for ($row=1; $row<=$y; $row++) {
        for ($col=1; $col<=$x; $col++) {
            if (rand(1,100) <= $density) {
                $p .= 'X';
            } else {
                $p .= ' ';
            }
        }
        $p .= "\n";
    }
    
    return $p;
}

/**
 * Parse pattern to rows/cols
 * 
 * @param string $pattern A multi-line
 * 
 * @return array [x:y] = 1/0
 */
function parsePattern($pattern)
{
    $data = array();
    
    $lines = explode("\n", $pattern);
    $row = 0;
    foreach ($lines as $line) {
        $row++;
        $cells = str_split($line, 1);
        $col = 0;
        foreach ($cells as $content) {
            $col++;
            $content = trim($content);
            if (!empty($content)) {
                $data["$row:$col"] = 1;
            }
        }
    }
    return $data;
}

/**
 * Show pattern
 * 
 * @param array $data
 * @param integer $x Num cols
 * @param integer $y Num rows
 */
function showPattern($data, $x, $y)
{
    $pattern = "";
    for ($r = 1; $r <= $y; $r++) {
        for ($c = 1; $c <= $x; $c++) {
            $str = "$r:$c";
            $pattern .= isset($data[$str]) ? '@' : '`';
        }
        $pattern .= "\n";
    }
    
    return $pattern;
}

/**
 * Remove a cell and its connected cells
 *
 * @param array $remaining The current stack of remaining
 * @param string $current As row:col
 * 
 * @return array 
 */
function removeConnectedStack($remaining, $current)
{
    $stack = array();
    array_push($stack, $current);
    
    while (!empty($stack)) {
        $current = array_pop($stack);
        unset($remaining[$current]);
        
        $coord = getCoords($current);
        $row = $coord['row'];
        $col = $coord['col'];

        // go round the houses
        //  N = $row-1 : $col
        //  E = $row : $col+1
        //  S = $row+1 : $col
        //  W = $row : $col-1
        if (isset($remaining[($row-1).":$col"])) {
            array_push($stack, ($row-1).":$col");
        }
        if (isset($remaining["$row:".($col+1)])) {
            array_push($stack, "$row:".($col+1));
        }
        if (isset($remaining[($row+1).":$col"])) {
            array_push($stack, ($row+1).":$col");
        }
        if (isset($remaining["$row:".($col-1)])) {
            array_push($stack, "$row:".($col-1));
        }
    }

    return $remaining;
}

/**
 * Remove a cell and its connected cells
 *
 * @param array $remaining The current stack of remaining
 * @param string $current As row:col
 * 
 * @return array 
 */
function removeConnectedRecursive($remaining, $current)
{
    unset($remaining[$current]);
    $coord = getCoords($current);
    $row = $coord['row'];
    $col = $coord['col'];

    // go round the houses
    //  N = $row-1 : $col
    //  E = $row : $col+1
    //  S = $row+1 : $col
    //  W = $row : $col-1
    if (isset($remaining[($row-1).":$col"])) {
        $remaining = removeConnectedRecursive($remaining, ($row-1).":$col");
    }
    if (isset($remaining["$row:".($col+1)])) {
        $remaining = removeConnectedRecursive($remaining, "$row:".($col+1));
    }
    if (isset($remaining[($row+1).":$col"])) {
        $remaining = removeConnectedRecursive($remaining, ($row+1).":$col");
    }
    if (isset($remaining["$row:".($col-1)])) {
        $remaining = removeConnectedRecursive($remaining, "$row:".($col-1));
    }
    return $remaining;
}


/**
 * Get coords
 *
 * @param string $string A string with $row:$col
 * 
 * @return array with keys: row, col 
 */
function getCoords($string)
{
    $row = substr($string, 0, strpos($string, ':'));
    $col = substr($string, strpos($string, ':') + 1);
    return array(
        'row' => $row,
        'col' => $col
        );
}