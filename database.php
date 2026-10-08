<?php 
$servername ='localhost';
$username ='root';
$password='';
$dbname ='social_network';



$conn = new mysqli($servername, $username, $password,$dbname);
if($conn->connect_error){
    die("Connection error". $conn->error);
}else{
   
}


?>


