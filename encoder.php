<?php
require_once 'aws/sdk.class.php';

define('ROOT', dirname(__FILE__));

global $s3;
global $sqs;
global $args;

$args = getArgs($_SERVER['argv']);

$s3 = new AmazonS3(array(
	'key' => $args['aws_key'],
	'secret' => $args['aws_secret']
));

$sqs = new AmazonSQS(array(
	'key' => $args['aws_key'],
	'secret' => $args['aws_secret']
));

$input_file_name = ROOT."/input_file";
if($args['output_format'] == 'mp4'){
	$output_file_name = ROOT."/randomoutput.mp4";
	$output_temp_file = ROOT."/random_output_tmp.mp4";
}else{
	$output_file_name = ROOT."/randomoutput.ogv";
}
$output_thumbnail_name = ROOT."/image_output_file.png";

cURL_file($s3->get_object_url($args['bucket'], $args['input_file']));

if((!file_exists($input_file_name)) || filesize($input_file_name) <= 1){
	/*
	 * If the file does not exist or is only 1 byte long (long enough for headers but not much else) then die
	 */
	send_SQS(false);
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
		send_SQS(false);
	}

	$duration = 0;
	if(preg_match('/duration=[0-9]+\.[0-9]+/', $format_section, $matches) > 0){ // Look for a duration within it all

		if(sizeof($matches) <= 0)
			send_SQS(false); // No duration is another problem

		$duration = preg_replace('/duration=/', '', $matches[0]); // Let's get the actual duration (i.e. 5.0998)

		if($duration <= 0 || preg_match('/[^0-9\.]+/', $duration) > 0){ // If duration is less than or equal to 0 or it contains anyhting but numbers and dots (i.e. 9.5432)
			send_SQS(false);
		}
	}
}else{
	send_SQS(false);
}

/**
 * OK so the file passed initial tests
 *
 * LETS ENCODE!!!!
 */
if($args['output_format'] == 'mp4'){
	$command = "ffmpeg -i $input_file_name -vcodec libx264 -r 100 -bt 300k -ac 2 -ar 48000 -ab 192k -strict -2 -y $output_temp_file 2>&1";
}elseif($args['output_format'] == 'ogv'){
	$command = "ffmpeg -i $input_file_name -s 640:480 -acodec libvorbis -vcodec libtheora -aspect 4:3 -r 20 -qscale 6 -ac 2 -ab 80k -ar 44100 -y $output_file_name 2>&1";
}

exec($command, $encoding_output); //-s 640:480 -aspect 4:3 -r 65535/2733 -qscale 5 -ac 2 -ar 48000 -ab 192k

if($args['output_format'] == 'mp4')
	exec("qt-faststart $output_temp_file $output_file_name");

echo "The command ran was: ".$command;
//var_dump($encoding_output);

$encoding_output_string = $encoding_output;
if(is_array($encoding_output)){
	$encoding_output_string = implode('\n', $encoding_output);
}

if(preg_match('/Error while opening encoder/', $encoding_output_string) > 0){
	send_SQS(false); // It means in undeniable error happened in encoding
}

/**
 * Now lets see if it validates and if it does lets put the finishing touches on
 */
if(validate_video($output_file_name)){

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
			$int_duration = rand(0, $matches[0] > 600 ? 600 : $matches[0]); // Let's limit the thumb time span by 10 mins cos else it takes a while
			exec("ffmpeg -itsoffset -$int_duration -i $input_file_name -r 100 -vcodec png -vframes 1 -an -f rawvideo -s 640x480 -y $output_thumbnail_name");

			if(file_exists($output_thumbnail_name) && filesize($output_thumbnail_name) > 0){ break; } // If we've got our image lets carry on
		}else{

			/*
			 * Last ditch attempt, get the first second
			 */
			exec("ffmpeg -itsoffset -1 -i $input_file_name -r 100 -vcodec png -vframes 1 -an -f rawvideo -s 640x480 -y $output_thumbnail_name");

			if(!file_exists($output_thumbnail_name) || filesize($output_thumbnail_name) <= 0){
				echo "died on images";
				send_SQS(false); // We couldn't seem to get an image for this
			}else{
				break;
			}
		}
	}

	/*
	 * Now lets recursively upload the video and its thumbnail back to S3 like good boys
	 */
	$v_upload_response = $s3->create_object($args['bucket'], pathinfo($output_file_name, PATHINFO_BASENAME), array(
		'acl' => AmazonS3::ACL_PUBLIC,
		'storage' => AmazonS3::STORAGE_REDUCED,
		'fileUpload' => $output_file_name
	));

	$failed = false;
	if($args['output_format'] == 'mp4'){
		$img_upload_response = $s3->create_object($args['bucket'], pathinfo($output_thumbnail_name, PATHINFO_BASENAME), array(
			'acl' => AmazonS3::ACL_PUBLIC,
			'storage' => AmazonS3::STORAGE_REDUCED,
			'fileUpload' => $output_thumbnail_name
		));

		if($v_upload_response->isOK() && $img_upload_response->isOK()){ }else{
			$failed = true;
		}
	}else{
		if($v_upload_response->isOK()){ }else{
			$failed = true;
		}
	}


	// If they uploaded fine lets cURL a success containing the possible URLs etc
	if(!$failed){
		echo "everything went ok";
		send_SQS(true, array(
			'url' => $s3->get_object_url($args['bucket'], pathinfo($output_file_name, PATHINFO_BASENAME)),
			'thumbnail' => $args['output_format'] == 'mp4' ? $s3->get_object_url($args['bucket'], pathinfo($output_thumbnail_name, PATHINFO_BASENAME)) : '',
			'duration' => (int)($duration*1000)
		));
	}else{
		echo "Shit coluldn't upload";
		send_SQS(false); /* FAIL */
	}
}else{
	send_SQS(false); /* FAIL */
}




/**
 * GLOBAL FUNCTIONS
 */

/**
 * GETARGS
 * @author              Patrick Fisher <patrick@pwfisher.com>
 */
function getArgs($argv){

	array_shift($argv);
	$out                            = array();

	foreach ($argv as $arg){

		// --foo --bar=baz
		if (substr($arg,0,2) == '--'){
			$eqPos                  = strpos($arg,'=');

			// --foo
			if ($eqPos === false){
				$key                = substr($arg,2);
				$value              = isset($out[$key]) ? $out[$key] : true;
				$out[$key]          = $value;
			}
			// --bar=baz
			else {
				$key                = substr($arg,2,$eqPos-2);
				$value              = substr($arg,$eqPos+1);
				$out[$key]          = $value;
			}
		}
		// -k=value -abc
		else if (substr($arg,0,1) == '-'){

			// -k=value
			if (substr($arg,2,1) == '='){
				$key                = substr($arg,1,1);
				$value              = substr($arg,3);
				$out[$key]          = $value;
			}
			// -abc
			else {
				$chars              = str_split(substr($arg,1));
				foreach ($chars as $char){
					$key            = $char;
					$value          = isset($out[$key]) ? $out[$key] : true;
					$out[$key]      = $value;
				}
			}
		}
		// plain-arg
		else {
			$value                  = $arg;
			$out[]                  = $value;
		}
	}
	return $out;
}


function validate_video($output_file_name, $output){

	global $args;

	if((!file_exists($output_file_name)) || filesize($output_file_name) <= 1){
		/*
		 * If the file does not exist or is only 1 byte long (long enough for headers but not much else) then die
		 */
		send_SQS(false);
	}

	exec("ffprobe -show_format -show_streams $output_file_name", $ffprobe_output);
	exec("ffmpeg -i $output_file_name 2>&1", $ffmpeg_output);

	if(empty($ffprobe_output) || empty($ffmpeg_output)){
		/*
		 * If the output is empty then it had problems opening the file for inspection
		 */
		send_SQS(false);
	}

	/*
	 * Now lets check for a STREAM (just one) and a FORMAT and check inside to see if they are OK
	 */
	$stringify_output = implode('\n', $ffprobe_output); // Lets join the output together with \n so we know the difference in lines
	$stream_section = magic_substr($stringify_output, '[STREAM]', '[/STREAM]'); // Lets get A STREAM section (notice the capital A)
	$format_section = magic_substr($stringify_output, '[FORMAT]', '[/FORMAT]'); // Lets extract a FORMAT section, if one

	/*
	 * If we don't have a FORMAT or STREAM section something must be terribly wrong
	 */
	if(strlen($format_section) <= 0 || strlen($stream_section) <= 0){
		send_SQS(false);
	}

	// Now lets test for a duration to our file then we can finally test for the codecs used

	$duration = 0;
	if(preg_match('/duration=[0-9]+\.[0-9]+/', $format_section, $matches) > 0){ // Look for a duration within it all

		if(sizeof($matches) <= 0)
			send_SQS(false); // No duration is another problem

		$duration = preg_replace('/duration=/', '', $matches[0]); // Let's get the actual duration (i.e. 5.0998)

		if($duration <= 0 || preg_match('/[^0-9\.]+/', $duration) > 0){ // If duration is less than 0 or it contains anyhting but numbers and dots (i.e. 9.5432)
			send_SQS(false);
		}

		/*
		 * It has got a duration obviously so lets check for codecs
		 */
	}

	if($args['output_format'] == 'mp4'){
		$audio_codec = 'aac';
		$video_codec = 'h264';
	}elseif($args['output_format'] == 'ogv'){
		$audio_codec = 'theora';
		$video_codec = 'vorbis';
	}

	if(preg_match("/codec_name=$audio_codec/", $stringify_output) > 0){ }else{ send_SQS(false); }
	if(preg_match("/codec_name=$video_codec/", $stringify_output) > 0){ }else{ send_SQS(false); }

	/*
	 * MY GOD! It might actually be a real file!
	 */
	return true;
}


function cURL_file($url){
	set_time_limit(0);
	$fp = fopen (dirname(__FILE__)."/input_file", 'w+');
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 75);
	curl_setopt($ch, CURLOPT_FILE, $fp);
	curl_exec($ch);
	curl_close($ch);
	fclose($fp);
}


function  magic_substr($string,$from,$to) {
	if(preg_match("!".preg_quote($from)."(.*?)".preg_quote($to)."!",$string,$m)) {
    	return $m[1];
    }
    return '';
}

function send_SQS($success, $fields = array()){

	global $sqs;
	global $args;

	$response = $sqs->send_message($args['output_queue'], json_encode(array_merge(array(
		'id' => $args['id'],
		'output_format' => $args['output_format'],
		'input_file' => $args['input_file'],
		'input_queue' => $args['input_queue'],
		'bucket' => $args['bucket'],
		'time_started' => $args['time_started'],
		'time_taken' => microtime(true) - $args['time_started'],
		'time_sent' => date('d-m-Y H:i:s'),
		'success' => $success
	), $fields)));

	if($response->isOk()){}
	exit(); // Send SQS is a one way ticket....a ticket to HELL
}


/*
 * Now lets exit to ensure no further processing is completed (not really needed but meh)
 */
exit();