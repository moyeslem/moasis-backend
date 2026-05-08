<?php
$db = new PDO('sqlite:c:/xampp/htdocs/moasis/back-end/data/mosais.sqlite');
$db->exec("UPDATE users SET is_admin = 0 WHERE username = 'mohamed'");
echo "done";
