<?php
namespace CmuLms;
final class Task extends Model {
 public static function forCourse(int $course,bool $includeClosed=false):array{
  /* Closed/overdue work remains visible to learners so they receive the
     required deadline message instead of silently losing the activity. */
  return self::all('SELECT * FROM tasks WHERE course_id=? ORDER BY due_at IS NULL,due_at',[$course]);
 }
}
