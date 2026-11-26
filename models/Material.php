<?php
namespace CmuLms;
final class Material extends Model {public static function forCourse(int $course):array{$student=(($_SESSION['user']['role']??'')==='student');$where=$student?'AND is_enabled=1':'';return self::all('SELECT * FROM course_materials WHERE course_id=? '.$where.' ORDER BY created_at DESC',[$course]);}public static function find(int $id):?array{return self::one('SELECT * FROM course_materials WHERE id=?',[$id]);}}
