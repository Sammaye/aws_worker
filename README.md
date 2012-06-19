AWS Worker
==========

An example AWS worker for job queues.

This worker basically takes an input FFMpeg file and output format and encodes the appropiate output while validating the output to ensure it is a real file and has been encoded correctly.

Due to the validation functions that are needed to be run you cannot send in your own FFMpeg commands but instead must use the ones baked into the script itself.

## Getting it to run

You must use a bootstrapper cronjob to get this worker to function. An example of one is:

    define('ROOT', dirname(__FILE__)); // Should be either /home/ec2-user or /home/ubuntu

    const AWS_KEY = '';
    const AWS_SECRET = '';
    const QUEUE = '';

	function logEvent($message){
		echo '[ '.date('d-m-Y H:i:s').' '.microtime(true).' ] '.$message.'\n';
	}

    exec('git clone https://github.com/Sammaye/aws_worker.git '.ROOT.'/worker');

    if(!file_exists(ROOT.'/worker/worker_despatcher.php')){
	    exit();
    }

    include_once ROOT.'/worker/worker_despatcher.php';

You would place this either in your AMI or in your cloud template within the LaunchConfig section.

This library does not require AWS to be downloaded but instead has it pre-bundled. I did this for a couple of reasons:

- Version freeze on the API
- Protected from sudden changes in core API
- Less time to download it all with the worker than to let Amazon do both separately and then prep and build the API from sources.

## Sending commands to the worker

The worker takes JSON syntax in a string.

Here is a sample of a OGV command:

    {"input_file": "4fa54b3ccacf54cb250000d8.divx", "bucket": "uploads", "output_format": "ogv", "output_queue": "https://us-west-2.queue.amazonaws.com//outputsQueue"}

And here is one of a MP4 command:

    {"input_file": "4fa54b3ccacf54cb250000d8.divx", "bucket": "uploads", "output_format": "mp4", "output_queue": "https://us-west-2.queue.amazonaws.com//outputsQueue"}

And here is an example of getting a thumbnail of your video:

	{"input_file": "4fa54b3ccacf54cb250000d8.divx", "bucket": "videos.stagex.co.uk", "output_format": "img", "output_queue": "https://us-west-2.queue.amazonaws.com/663341881510/stagex-outputsQueue"}

You would send these strings as the message body in a SQS Message to your main input queue to either your server pooler or your cloud formation template.

## Outputs Supported

- mp4
- ogv
- img

These output labels go into the output_format field within the JSON encoded SQS message body.

## Outputting

The encoder will automatically use the AWS API (not downloaded via Cloudinit) to upload not only the appropiate output you asked for but also the video thumbnail straight to the S3 bucket you
specified within the bucket param inside of the SQS message.