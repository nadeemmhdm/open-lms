<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
use OpenLMS\Services\TotpService;
$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$vectors=[59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'];
foreach($vectors as $time=>$expected){$actual=TotpService::generateCode($secret,(int)$time,8);if(!hash_equals($expected,$actual)){fwrite(STDERR,"TOTP mismatch at $time: $actual != $expected\n");exit(1);}}
echo "PASS: RFC 6238 SHA-1 test vectors\n";
