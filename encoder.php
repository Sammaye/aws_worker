<?php

require_once 'aws/sdk.class.php';
require_once 'global.php';

$args = getArgs();
$payload = $args['payload'];

$failed = false;

$input_file_name = dirname(__FILE__)."/input_file";

$output_file_name = dirname(__FILE__)."/randomoutput.mp4";
$output_temp_file = dirname(__FILE__)."/random_output_tmp.mp4";
$output_thumbnail_name = dirname(__FILE__)."/image_output_file.png";

// get ffmpeg version
exec('ffmpeg -version 2>&1', $ffmpeg_version_output);
//echo "FFmpeg version: {$output}\n";
$ffmpeg_config = $ffmpeg_version_output[2];

$s3 = new AmazonS3(array(
	'key' => $payload->aws_key,
	'secret' => $payload->aws_secret
));

//download($s3->get_object_url($payload->bucket, $payload->file_name), $input_file_name);
cURL_file($s3->get_object_url($payload->bucket, $payload->file_name));

if((!file_exists($input_file_name)) || filesize($input_file_name) <= 1){
	/*
	 * If the file does not exist or is only 1 byte long (long enough for headers but not much else) then die
	 */
	fail_cURL('mp4');
}

exec("ffprobe -show_format -show_streams $input_file_name", $output); // Let's butt probe this file to find out if it's valid
//print_r($output);

// Now lets get the info we need to validate and get duration
if(!empty($output)){

	$stringify_output = implode('\n', $output); // Lets join the output together with \n so we know the difference in lines
	$stream_section = magic_substr($stringify_output, '[STREAM]', '[/STREAM]'); // Lets get A STREAM section (notice the capital A)
	$format_section = magic_substr($stringify_output, '[FORMAT]', '[/FORMAT]'); // Lets extract a FORMAT section, if one

	/*
	 * If we don't have a FORMAT or STREAM section something must be terribly wrong
	 */
	if(strlen($format_section) <= 0 || strlen($stream_section) <= 0){
		fail_cURL('mp4');
	}

	$duration = 0;
	if(preg_match('/duration=[0-9]+\.[0-9]+/', $format_section, $matches) > 0){ // Look for a duration within it all

		if(sizeof($matches) <= 0)
			fail_cURL('mp4'); // No duration is another problem

		$duration = preg_replace('/duration=/', '', $matches[0]); // Let's get the actual duration (i.e. 5.0998)

		if($duration <= 0 || preg_match('/[^0-9\.]+/', $duration) > 0){ // If duration is less than or equal to 0 or it contains anyhting but numbers and dots (i.e. 9.5432)
			fail_cURL('mp4');
		}
	}
}else{
	fail_cURL('mp4');
}

/**
 * OK so the file passed initial tests
 *
 * LETS ENCODE!!!!
 */
$command = "ffmpeg -i $input_file_name -s 640:480 -vcodec libx264 -aspect 4:3 -r 100 -qscale 5 -b 300k -bt 300k -ac 2 -ar 48000 -ab 192k -y $output_temp_file 2>&1";
exec($command, $encoding_output); //-s 640:480 -aspect 4:3 -r 65535/2733 -qscale 5 -ac 2 -ar 48000 -ab 192k
exec("qt-faststart $output_temp_file $output_file_name");

echo "The command ran was: ".$command;
var_dump($encoding_output);

$encoding_output_string = $encoding_output;
if(is_array($encoding_output)){
	$encoding_output_string = implode('\n', $encoding_output);
}

if(preg_match('/Error while opening encoder/', $encoding_output_string) > 0){
	fail_cURL('mp4'); // It means in undeniable error happened in encoding
}

/**
 * Now lets see if it validates and if it does lets put the finishing touches on
 */
if(validate_video($output_file_name, 'mp4', 'aac', 'mpeg4')){

	/*
	 * From our duration we got earlier lets get a random second between 0 and max second without rounding and check the image file is real by chekcing its size is greater
	 * than 0
	 */

	preg_match('/^[0-9]+/', $duration, $matches); // Lets get a strict int of the duration

	// Lets try it 5 times else we will just get first frame else die mofo
	for($i=0;$i<5;$i++){
		if($i < 4){
			/*
			 * For the first 4 tries lets get a random image
			 */
			$int_duration = rand(0, $matches[0]);
			exec("ffmpeg -itsoffset -$int_duration -i $input_file_name -vcodec png -vframes 1 -an -f rawvideo -s 640x480 $output_thumbnail_name");

			if(file_exists($output_thumbnail_name) && filesize($output_thumbnail_name) > 0){ break; } // If we've got our image lets carry on
		}else{

			/*
			 * Last ditch attempt, get the first second
			 */
			exec("ffmpeg -itsoffset -1 -i $input_file_name -vcodec png -vframes 1 -an -f rawvideo -s 640x480 $output_thumbnail_name");

			if(!file_exists($output_thumbnail_name) || filesize($output_thumbnail_name) <= 0){
				fail_cURL('mp4'); // We couldn't seem to get an image for this
			}else{
				break;
			}
		}
	}

	/*
	 * Now lets recursively upload the video and its thumbnail back to S3 like good boys
	 */
	$v_upload_response = $s3->create_object($payload->bucket, 'randomOutput.mp4', array(
		'acl' => AmazonS3::ACL_PUBLIC,
		'storage' => AmazonS3::STORAGE_REDUCED,
		'fileUpload' => $output_file_name
	));

	$img_upload_response = $s3->create_object($payload->bucket, 'randomThumbnail.png', array(
		'acl' => AmazonS3::ACL_PUBLIC,
		'storage' => AmazonS3::STORAGE_REDUCED,
		'fileUpload' => $output_thumbnail_name
	));

	// If they uploaded fine lets cURL a success containing the possible URLs etc
	if($v_upload_response->isOK() & $img_upload_response->isOK()){
		echo "everything went ok"; exit();
		success_cURL(array(
			'output' => 'mp4',
			'url' => $s3->get_object_url($payload->bucket, 'randomOutput.mp4'),
			'thumbnail' => $s3->get_object_url($payload->bucket, 'randomThumbnail.png'),
			'duration' => (int)($duration*1000)
		));
	}else{
		fail_cURL('mp4'); /* FAIL */
	}
}else{
	fail_cURL('mp4'); /* FAIL */
}

/*
 * Now lets exit to ensure no further processing is completed (not really needed but meh)
 */
exit();