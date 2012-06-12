<?php
include_once 'aws/sdk.class.php';
$time_start = microtime(true);

/*
 * If the lock file does not exist lets make it
 */
if(!file_exists(ROOT.'/worker/lock.file')){
	touch(ROOT.'/lock.file');
}

$fp = fopen(ROOT."/worker/lock.file", "r+");
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

/*
 * Check integrity of message commands, if something is missing bail
 */
if(!isset($message->messageid) || !isset($message['bucket']) || !isset($message['input_file']) || !isset($message['output_format']) || !isset($message['output_queue'])){
	flock($fp, LOCK_UN);    // release the lock
	fclose($fp);
	exit();
}

$args = array(
	'id' => $message->messageid,
	'aws_key' => AWS_KEY,
	'aws_secret' => AWS_SECRET,
	'bucket' => $message['bucket'],
	'input_queue' => QUEUE,
	'output_queue' => $message['output_queue'],
	'output_format' => $message['output_format'],
	'input_file' => $message['input_file'],
	'time_started' => $time_start,
);

$arg_string = '';
foreach($args as $k=>$v){
	$arg_string .= ' --'.$k.'='.$v;
}

//exec(sprintf("%s > %s 2>&1 & echo $! >> %s", "php ".ROOT."/worker/encoder.php --input=".$message['input']." --output=".$message['output'], ROOT."/worker/encoder.log", "./pid.file"));
$PID = exec(sprintf("%s > %s 2>&1 & echo $!", "php ".ROOT."/worker/encoder.php".$arg_string, ROOT."/worker/encoder.log"));
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