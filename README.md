AWS Worker
==========

An example AWS worker for job queues.

This worker basically takes an input FFMpeg file and output format and encodes the appropiate output while validating the output to ensure it is a real file and has been encoded correctly.

Due to the validation functions that are needed to be run you cannot send in your own FFMpeg commands but instead must use the ones baked into the script itself.

# Outputs Supported

- mp4
- ogv

These output labels go into the output_format field within the JSON encoded SQS message body.

# Outputting

The encoder will automatically use the AWS API (not downloaded via Cloudinit) to upload not only the appropiate output you asked for but also the video thumbnail straight to the S3 bucket you
specified within the bucket param inside of the SQS message.