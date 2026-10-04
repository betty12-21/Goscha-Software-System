<?php
$srcFile = 'C:/xampp/htdocs/Beauty salon/modules/reports.php';
$src = file_get_contents($srcFile);
$tokens = token_get_all($src);
$out = '';
$cur = 1;
foreach ($tokens as $tk) {
    if (is_array($tk)) {
        $line = $tk[2];
        switch ($tk[0]) {
            case T_IF:            $out .= "L{$line}: IF\n"; break;
            case T_ELSEIF:        $out .= "L{$line}: ELSEIF\n"; break;
            case T_ELSE:          $out .= "L{$line}: ELSE\n"; break;
            case T_ENDIF:         $out .= "L{$line}: ENDIF\n"; break;
            case T_FOREACH:       $out .= "L{$line}: FOREACH\n"; break;
            case T_ENDFOREACH:    $out .= "L{$line}: ENDFOREACH\n"; break;
        }
    }
}
$outFile = 'C:/Users/hp/AppData/Local/Temp/opencode/outline.txt';
file_put_contents($outFile, $out);
echo "WROTE: " . strlen($out) . " bytes\n";
