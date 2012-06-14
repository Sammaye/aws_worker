AWS Worker
==========

An example AWS worker for job queues.

This worker basically takes an input FFMpeg file and output format and encodes the appropiate output while validating the output to ensure it is a real file and has been encoded correctly.

Due to the validation functions that are needed to be run you cannot send in your own FFMpeg commands but instead must use the ones baked into the script itself.

## Sending commands to the worker

The worker takes JSON syntax in a string.

Here is a sample of a OGV command:

    {"input_file": "4fa54b3ccacf54cb250000d8.divx", "bucket": "uploads", "output_format": "ogv", "output_queue": "https://us-west-2.queue.amazonaws.com//outputsQueue"}

And here is one of a MP4 command:

    {"input_file": "4fa54b3ccacf54cb250000d8.divx", "bucket": "uploads", "output_format": "mp4", "output_queue": "https://us-west-2.queue.amazonaws.com//outputsQueue"}
    
You would send these strings as the message body in a SQS Message.

## Outputs Supported

- mp4
- ogv

These output labels go into the output_format field within the JSON encoded SQS message body.

## Outputting

The encoder will automatically use the AWS API (not downloaded via Cloudinit) to upload not only the appropiate output you asked for but also the video thumbnail straight to the S3 bucket you
specified within the bucket param inside of the SQS message.