<?php
// Fake "published CSV": the real Google one allows cross-origin reads too.
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/csv');
readfile(__DIR__ . '/members.csv');
