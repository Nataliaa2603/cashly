<?php
session_start(); require __DIR__.'/../config/config.php'; require __DIR__.'/../app/core/Database.php'; require __DIR__.'/../app/core/helpers.php';
spl_autoload_register(function($c){foreach([__DIR__.'/../app/models/'.$c.'.php',__DIR__.'/../app/controllers/'.$c.'.php'] as $f)if(is_file($f))require_once $f;});
$defaultPage=empty($_SESSION['user'])?'login':(((isset($_SESSION['user']['role']) && $_SESSION['user']['role']==='admin')?'admin':'inicio')); $page=$_GET['page']??$defaultPage;
if(in_array($page,['login','registro','recuperar','restablecer','salir'],true)){ $c=new AuthController(); $map=['login'=>'login','registro'=>'register','recuperar'=>'forgot','restablecer'=>'reset','salir'=>'logout'];$method=$map[$page];$c->$method();exit; }
(new AppController())->run($page);
