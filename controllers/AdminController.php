<?php
namespace CmuLms;
final class AdminController { public function users():void{$u=Auth::role('admin');View::render('admin/users',['user'=>$u,'students'=>User::students(['q'=>$_GET['q']??''])]);}public function toggle(\PDO $db):never{$u=Auth::role('admin');View::checkCsrf();User::setDisabled((int)$_POST['user_id'],($_POST['state']??'')==='disable');Http::redirect('?route=admin-users');} }
