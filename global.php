<?php

function fail_cURL($output){
	echo "Video Failed";
	exit();

	$data = array('job_id' => $payload->job_id, 'output' => $output, "state" => "failed");
	$data_string = json_encode($data);

	$ch = curl_init('http://www.stagex.co.uk/video/post_process');
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	    'Content-Type: application/json',
	    'Content-Length: ' . strlen($data_string))
	);

	$result = curl_exec($ch);

	exit();
}

function success_cURL($args){
	$data = array_merge($args, array('job_id' => $payload->job_id, "state" => "completed"));
	$data_string = json_encode($data);

	$ch = curl_init('http://www.stagex.co.uk/video/post_process');
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	    'Content-Type: application/json',
	    'Content-Length: ' . strlen($data_string))
	);

	$result = curl_exec($ch);

	exit();
}


function validate_video($output_file_name, $output, $audio_codec = 'aac', $video_codec = 'h264'){

	if((!file_exists($output_file_name)) || filesize($output_file_name) <= 1){
		/*
		 * If the file does not exist or is only 1 byte long (long enough for headers but not much else) then die
		 */
		fail_cURL($output);
	}

	exec("ffprobe -show_format -show_streams $output_file_name", $ffprobe_output);
	exec("ffmpeg -i $output_file_name 2>&1", $ffmpeg_output);

	if(empty($ffprobe_output) || empty($ffmpeg_output)){
		/*
		 * If the output is empty then it had problems opening the file for inspection
		 */
		fail_cURL($output);
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
		fail_cURL($output);
	}

	// Now lets test for a duration to our file then we can finally test for the codecs used

	$duration = 0;
	if(preg_match('/duration=[0-9]+\.[0-9]+/', $format_section, $matches) > 0){ // Look for a duration within it all

		if(sizeof($matches) <= 0)
			fail_cURL($output); // No duration is another problem

		$duration = preg_replace('/duration=/', '', $matches[0]); // Let's get the actual duration (i.e. 5.0998)

		if($duration <= 0 || preg_match('/[^0-9\.]+/', $duration) > 0){ // If duration is less than 0 or it contains anyhting but numbers and dots (i.e. 9.5432)
			fail_cURL($output);
		}

		/*
		 * It has got a duration obviously so lets check for codecs
		 */
	}

	//	var_dump($stringify_output); // For debug
	if(preg_match("/codec_name=$audio_codec/", $stringify_output) > 0){ }else{ fail_cURL($output); }
	if(preg_match("/codec_name=$video_codec/", $stringify_output) > 0){ }else{ fail_cURL($output); }

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

function download($file_source, $file_target)
{
    $rh = fopen($file_source, 'rb');
    $wh = fopen($file_target, 'wb');
    if (!$rh || !$wh) {
        return false;
    }

    while (!feof($rh)) {
        if (fwrite($wh, fread($rh, 1024)) === FALSE) {
            return false;
        }
    }

    fclose($rh);
    fclose($wh);

    return true;
}
