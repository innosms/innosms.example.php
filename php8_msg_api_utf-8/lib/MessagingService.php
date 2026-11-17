<?php

/**
 * @file MessagingService.php
 * @brief REST API Messaging module (utf-8) - PHP 8.4 compatible
 * @author INNOPOST (tech@innopost.com)
 */

class MessagingService
{
   private string $ServiceURL = "https://api.innosms.com";
   private string $Version = "1";

   private string $UserID;
   private string $APIKey;
   private ?string $Token = null;

   // 운영 서버에 CA 번들 문제가 있어 임시로 꺼야 한다면 false 로 변경 (권장하지 않음)
   private bool $VerifySSL = true;

   public function __construct(string $UserID, string $APIKey){
      $this->UserID = $UserID;
      $this->APIKey = $APIKey;

      $this->Token = $this->getToken();
   }

   public function __destruct(){
      if(!empty($this->Token)){
         try{
            $this->deleteToken();
         }catch(Throwable $e){
         }
      }
   }

   private function executeCURL(string $uri, ?string $method = null, array $header = [], $postdata = null, bool $isMultiPart = false){
      $http = curl_init($this->ServiceURL."/".$this->Version."/".$uri);

      if($isMultiPart) {
         $header[] = "Content-Type:multipart/form-data";
      }else{
         $header[] = "Content-Type:application/x-www-form-urlencoded;charset=utf-8;";

         if($postdata){
            $temp = [];
            foreach($postdata as $k => $v){
               if ($k == "msg" || $k == "subject")
                  $v = urlencode((string)$v);

               $temp[] = $k."=".$v;
            }
            // PHP 8.0 부터 implode(array, string) 순서는 지원되지 않음
            $postdata = implode("&", $temp);
         }
      }

      $isPost = ($method === "POST");

      $options = [
         CURLOPT_POST => $isPost,
         CURLOPT_SSL_VERIFYPEER => $this->VerifySSL,
         CURLOPT_HEADER => 0,
         CURLOPT_TIMEOUT => 10,
         CURLOPT_RETURNTRANSFER => 1,
         CURLOPT_HTTPHEADER => $header,
      ];

      if($method !== null){
         $options[CURLOPT_CUSTOMREQUEST] = $method;
      }

      if($postdata !== null){
         $options[CURLOPT_POSTFIELDS] = $postdata;
      }

      curl_setopt_array($http, $options);

      $responseJson = curl_exec($http);
      $http_status = curl_getinfo($http, CURLINFO_HTTP_CODE);

      if($responseJson === false){
         throw new APIException(json_encode([
            "code" => 10001,
            "message" => "cURL error: ".curl_error($http),
         ]));
      }

      if($http_status != 200){
         throw new APIException((string)$responseJson);
         //오류코드 배열로 수신
         //$result = new APIException($responseJson);
         //$returnResult = $result->toArray();
      }else{
         $returnResult = json_decode($responseJson, true);
      }

      return $returnResult;
   }

   private function convertEuckrToUtf8($str) {
      if(is_array($str)){
         $returnResult = [];
         foreach($str as $key => $val){
            $returnResult[$key] = is_array($val)
               ? $this->convertEuckrToUtf8($val)
               : mb_convert_encoding((string)$val, "UTF-8", "EUC-KR");
         }
      }else{
         $returnResult = mb_convert_encoding((string)$str, "UTF-8", "EUC-KR");
      }

      return $returnResult;
   }

   private function setContent(array $contents, bool $isPost = false){
      $result = null;

      if($isPost)
      {
         $result = [];
         foreach($contents as $key => $val)
         {
            if($key == "image")
            {
               $path = !empty($val) ? realpath((string)$val) : false;
               $result[$key] = ($path !== false) ? new CURLFile($path) : "";
            }
            else{
               if($key == "msg_list"){
                  $val = json_encode($val);
               }

               $result[$key] = $val;
            }
         }
      }
      else
      {
         $result = "";
         foreach($contents as $val){
            $result .= "/".urlencode((string)$val);
         }
      }

      return $result;
   }

   private function getToken(): ?string{
      try{
         $header = [];
         $header[] = "Authorization: Basic ".base64_encode($this->UserID.":".$this->APIKey);
         $result = $this->executeCURL("token", "POST", $header);
         return $result['token'] ?? null;
      }catch(APIException $e){
         echo $e;
         exit;
      }
   }

   private function deleteToken(){
      $header = [];
      $header[] = "Authorization: Bearer ".$this->Token;
      $this->executeCURL("token", "DELETE", $header);
   }

   public function getBalance(){
      try{
         $header = [];
         $header[] = "Authorization: Bearer ".$this->Token;
         return $this->executeCURL("balance", "GET", $header);
      }catch(APIException $e){
         echo $e;
         exit;
      }
   }

   public function sendMessage(array $contents){
      try{
         $header = [];
         $header[] = "Authorization: Bearer ".$this->Token;
         $isMultiPart = (($contents['msg_type'] ?? null) === "mms");
         return $this->executeCURL("send", "POST", $header, $this->setContent($contents, true), $isMultiPart);
      }catch(APIException $e){
         echo $e;
         exit;
      }
   }

   public function getMessage(array $contents){
      try{
         $header = [];
         $header[] = "Authorization: Bearer ".$this->Token;
         return $this->executeCURL("send".$this->setContent($contents), "GET", $header);
      }catch(APIException $e){
         echo $e;
         exit;
      }
   }

   public function cancelReservation(array $contents){
      try{
         $header = [];
         $header[] = "Authorization: Bearer ".$this->Token;
         return $this->executeCURL("reservation".$this->setContent($contents), "DELETE", $header);
      }catch(APIException $e){
         echo $e;
         exit;
      }
   }
}

class APIException extends Exception
{
   public function __construct(string $response, int $code = 10000, ?Throwable $previous = null) {
      $Err = json_decode($response);

      if(is_object($Err) && isset($Err->message)) {
         parent::__construct((string)$Err->message, (int)($Err->code ?? $code), $previous);
      }
      else {
         parent::__construct($response, $code, $previous);
      }
   }

   public function toArray(): array {
      return [
         "code" => $this->code,
         "message" => mb_convert_encoding($this->message, "EUC-KR", "UTF-8"),
      ];
   }

   public function __toString(): string {
      return __CLASS__ . ": [{$this->code}]: ".$this->message."\n";
   }
}