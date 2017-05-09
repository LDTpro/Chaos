<?php

require 'config.php';

$conn = mysqli_connect($DBHOST, $DBUSER, $DBPW, $DBNAME);

if (isset($_GET['hub_verify_token'])) { 
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
        return;
    } else {
        echo 'Verification token mismatch';
        return;
    }
}

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['entry'][0]['messaging'][0]['sender']['id'])) {
	$sender = $data['entry'][0]['messaging'][0]['sender']['id'];
}

if (isset($data['entry'][0]['messaging'][0]['message']['text'])) {
	$message = $data['entry'][0]['messaging'][0]['message']['text'];
    if ($message === "bot")
        sendButton($sender);
    else 
        forwardMessage($sender, $message);
}

if (isset($data['entry'][0]['messaging'][0]['postback']['payload'])) {
	$postback = $data['entry'][0]['messaging'][0]['postback']['payload'];
    if ($postback === "start") {
        if (checkUser($sender))
            if (checkStatus($sender) === 0)
                findRelationship($sender);
            else 
                sendMessage($sender, "Sorry, you can't start new conversation, you must end this conversation first");
        else {
            addUser($sender);
            findRelationship($sender);
        }
    }
    if ($postback === "stop") {
        if (checkUser($sender)) {
            if (checkStatus($sender) === 0)
                sendMessage($sender, "Sorry, you don't have any conversation to end");
            else
                deleteRelationship($sender);
        }
        else
            sendMessage($sender, "Sorry, you don't have any conversation to end");
    }
}

function request($jsondata) {
    $ACCESS_TOKEN = $GLOBALS["ACCESS_TOKEN"];
	$url = "https://graph.facebook.com/v2.9/me/messages?access_token=$ACCESS_TOKEN";
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsondata);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_exec($ch);
}

function sendMessage($receiver, $content) {
    $payload = '{"recipient":{"id":"'.$receiver.'"},"message":{"text":"'.$content.'"}}';
    request($payload);
}

function sendButton($receiver) {
    $payload = '{"recipient": {"id": '.$receiver.'}, "message":{"attachment":{"type":"template","payload":{"template_type":"button","text":"What do you want to do next?","buttons":[{"type":"postback","title":"Start Chatting","payload":"start"},{"type":"postback","title":"End Conversation","payload":"stop"}]}}}}';
    request($payload);
}

function checkUser($userid) {
    $result = mysqli_query($GLOBALS["conn"], "SELECT * from users WHERE id =$userid LIMIT 1");
    $row = mysqli_num_rows($result);
    return $row;
}

function checkStatus($userid) {
    $result = mysqli_query($GLOBALS["conn"], "SELECT status from users WHERE id =$userid ");
    $row = mysqli_fetch_assoc($result);
    $status = $row['status'];
    return $status;
}

function getRelationship($userid) {
    $result = mysqli_query($GLOBALS["conn"], "SELECT relationship from users WHERE id =$userid ");
    $row = mysqli_fetch_assoc($result);
    $relationship = $row['relationship'];
    return $relationship;
}

function addRelationship($user1, $user2) {
    mysqli_query($GLOBALS["conn"], "UPDATE users SET status = 1, relationship =$user2  WHERE id =$user1 ");
    mysqli_query($GLOBALS["conn"], "UPDATE users SET status = 1, relationship =$user1  WHERE id =$user2 ");
}

function deleteRelationship($userid) {
    $partner = getRelationship($userid);
    mysqli_query($GLOBALS["conn"], "UPDATE users SET status = 0, relationship = NULL WHERE id =$userid ");
    mysqli_query($GLOBALS["conn"], "UPDATE users SET status = 0, relationship = NULL WHERE id =$partner ");
}

function addUser($userid) {
    mysqli_query($GLOBALS["conn"], "INSERT INTO users (id, status) VALUES ($userid, 0)");
}

function forwardMessage($userid, $msg) {
    $partner = getRelationship($userid);
    if ($partner !== false)
        sendMessage($partner, $msg);
}

function findRelationship($userid) {
    $partner = mysqli_query($GLOBALS["conn"], "SELECT id FROM user WHERE status = 0 AND id != $userid ORDER BY RAND() LIMIT 1");
    if ($partner === false)
        sendMessage($userid, "Sorry, no stranger available now");
    else {
        addRelationship($userid, $partner);
        sendMessage($userid, "You have been connected with the stranger");
        sendMessage($partner, "You have been connected with the stranger");
    }
}

mysqli_close($conn);

?>