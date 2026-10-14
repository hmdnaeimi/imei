<?php
declare(strict_types=1);require_once __DIR__.'/lib.php';
if($_SERVER['REQUEST_METHOD']==='POST'){try{check_csrf($_POST['csrf']??null);$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();header('Location: index.php');exit;}catch(AppError $e){http_response_code(403);exit(e($e->getMessage()));}}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/app.css"><title>خروج از دفتر</title></head><body><main class="setup-shell"><section class="panel setup-panel login-panel"><h1>خروج از حساب</h1><form method="post"><input name="csrf" type="hidden" value="<?=e(csrf())?>"><button class="primary">خروج از حساب</button><a class="secondary" href="index.php">بازگشت</a></form></section></main></body></html>

