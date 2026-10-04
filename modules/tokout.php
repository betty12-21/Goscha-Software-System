<?php
$f = 'C:/xampp/htdocs/Beauty salon/modules/reports.php';
$s = file_get_contents($f);
$t = token_get_all($s);
$o = '';
foreach ($t as $tk) {
    if (is_array($tk)) {
        $c = $tk[0];
        $l = $tk[2];
        if ($c === T_IF) $o .= "$l IF\n";
        if ($c === T_ELSEIF) $o .= "$l ELSEIF\n";
        if ($c === T_ELSE) $o .= "$l ELSE\n";
        if ($c === T_ENDIF) $o .= "$l ENDIF\n";
    }
}
file_put_contents('C:/Users/hp/AppData/Local/Temp/opencode/tok.txt', $o);
echo 'len=' . strlen($o);
