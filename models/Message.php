<?php
namespace CmuLms;
final class Message extends Model {public static function forUser(int $id):array{return self::all('SELECT m.*,s.name sender_name,r.name recipient_name FROM messages m JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.recipient_id WHERE m.sender_id=? OR m.recipient_id=? ORDER BY m.created_at DESC',[$id,$id]);}public static function unread(int $id):int{return (int)self::one('SELECT COUNT(*) c FROM messages WHERE recipient_id=? AND read_at IS NULL',[$id])['c'];}}
