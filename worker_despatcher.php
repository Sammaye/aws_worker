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
	logEvent('Couold not get lock');
   	exit(); // Could not aquire as such just die
}
logEvent('Got Lock!');

$sqs = new AmazonSQS(array(
	'key' => AWS_KEY,
	'secret' => AWS_SECRET
));

/**
 * So I have my repo and my code and I have lock. Lets start this shit
 */
$sqs_message = $sqs->receive_message(QUEUE, array(
    'VisibilityTimeout' => 240
));

if(!isset($sqs_message->body->ReceiveMessageResult->Message)){
	logEvent('No Message Found, releasing lock');
	flock($fp, LOCK_UN);    // release the lock
	fclose($fp);
	exit();
}

$message = json_decode($sqs_message->body->ReceiveMessageResult->Message->Body);
/*
 * Check integrity of message commands, if something is missing bail
 */
if(!isset($message->job_id) || !isset($message->bucket) || !isset($message->input_file) || !isset($message->output_format) || !isset($message->output_queue)){
	logEvent("The SQS Message was Malformed");
	flock($fp, LOCK_UN);    // release the lock
	fclose($fp);
	exit();
}

$args = array(
	'id' => $message->job_id,
	'aws_key' => AWS_KEY,
	'aws_secret' => AWS_SECRET,
	'bucket' => $message->bucket,
	'input_queue' => QUEUE,
	'output_queue' => $message->output_queue,
	'output_format' => $message->output_format,
	'input_file' => $message->input_file,
	'time_started' => $time_start,
);

$arg_string = '';
foreach($args as $k=>$v){
	$arg_string .= ' --'.$k.'='.$v;
}

logEvent('Calling process');

//exec(sprintf("%s > %s 2>&1 & echo $! >> %s", "php ".ROOT."/worker/encoder.php --input=".$message['input']." --output=".$message['output'], ROOT."/worker/encoder.log", "./pid.file"));
$PID = exec(sprintf("%s > %s 2>&1 & echo $!", "php ".ROOT."/worker/encoder.php".$arg_string, ROOT."/worker/encoder.log"));
logEvent('PID: '.$PID);
//$PID = shell_exec("nohup php ./worker/encoder.php 2> ./worker/encoder.log & echo $!");

if(strlen($PID) <= 0){ // This denotes that no PID was returned, this could mean the process couldn't run for some reason
	logEvent("No process found!!");
	$sqs->change_message_visibility(QUEUE, $sqs_message->body->ReceiveMessageResult->Message->ReceiptHandle, 10);
	flock($fp, LOCK_UN);    // release the lock // Don't delete the SQS message could the process might not have run at all
	fclose($fp);
	exit();
}

/**
 * I want this script to run until the task completes.
 * It is basically a watch dog that will end the cronjob etc if the process
 * stalls or server becomes unhealthy
 */
while(isRunning($PID)){ // Start the loop to wait until the task is complete
	// Delay SQS
	$sqs->change_message_visibility(QUEUE, $sqs_message->body->ReceiveMessageResult->Message->ReceiptHandle, 240);
	sleep(30);
}

logEvent("Done! Deleting Message.");
$sqs->delete_message(QUEUE, $sqs_message->body->ReceiveMessageResult->Message->ReceiptHandle);

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