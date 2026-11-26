<?php
namespace CmuLms;
final class DashboardController { public function index():void{$u=Auth::require();View::render('dashboard/index',['user'=>$u,'courses'=>Course::forUser($u),'exams'=>$u['role']==='student'?Exam::forStudent($u['id']):[],'grades'=>$u['role']==='student'?Grade::forStudent($u['id']):[]]);} }
