<?php
include_once ROOT.'/aws/sdk.class.php';

/*
 * If the lock file does not exist lets make it
 */
if(!file_exists(ROOT.'/lock.file')){
	touch(ROOT.'/lock.file');
}

$fp = fopen(ROOT."/lock.file", "r+");
if (!flock($fp, LOCK_EX)) {  // acquire an exclusive lock
   	exit(); // Could not aquire as such just die
}

$sqs = new AmazonSQS(array(
	'key' => AWS_KEY,
	'secret' => AWS_SECRET
));


/**
 * So I have my repo and my code and I have lock. Lets start this shit
 */
$sqs_message = $sqs->receive_message(QUEUE, array(
    'VisibilityTimeout' => 120
));

$message = json_decode($sqs_message->getMessage());
//exec(sprintf("%s > %s 2>&1 & echo $! >> %s", "php ".ROOT."/worker/encoder.php --input=".$message['input']." --output=".$message['output'], ROOT."/worker/encoder.log", "./pid.file"));
$PID = exec(sprintf("%s > %s 2>&1 & echo $!", "php ".ROOT."/worker/encoder.php --input=".$message['input']." --output=".$message['output'], ROOT."/worker/encoder.log"));
//$PID = shell_exec("nohup php ./worker/encoder.php 2> ./worker/encoder.log & echo $!");

sleep(60);

//$pid = file_get_contents(ROOT.'/pid.file');
if(!isRunning($PID)){
	flock($fp, LOCK_UN);    // release the lock
	fclose($fp);
	exit();
}

$sqs->change_message_visibility(QUEUE, $sqs_message->receipt_handle, 120);

/**
 * I want this script to run until the task completes.
 * It is basically a watch dog that will end the cronjob etc if the process
 * stalls or server becomes unhealthy
 */
while(isRunning($PID)){ // Start the loop to wait until the task is complete
	// Delay SQS
	$sqs->change_message_visibility(QUEUE, $sqs_message->receipt_handle, 120);
	sleep(60);
}
$sqs->delete_message(Queue, $sqs_message->receipt_handle);

flock($fp, LOCK_UN);    // release the lock
fclose($fp);



function isRunning($pid){
    try{
        $result = shell_exec(sprintf("ps %d", $pid));
        if( count(preg_split("/\n/", $result)) > 2){
            return true;
        }
    }catch(Exception $e){}

    return false;
}