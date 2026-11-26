<?php
namespace CmuLms;

final class PasswordRecovery {
    public const GRANT_SECONDS = 300;
    private const WINDOW_SECONDS = 900;

    public function __construct(private \PDO $db) {}

    public static function install(\PDO $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS password_recovery_limits (
            key_hash CHAR(64) NOT NULL PRIMARY KEY,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX recovery_limits_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private static function normalizeName(string $name): string {
        $name=preg_replace('/[\p{Z}\s]+/u',' ',trim($name))??'';
        if(class_exists('Normalizer')) $name=\Normalizer::normalize($name,\Normalizer::FORM_C)?:$name;
        return mb_strtolower($name,'UTF-8');
    }

    private function transaction(callable $work): array {
        $own=!$this->db->inTransaction();
        if($own) $this->db->beginTransaction();
        try {$result=$work();if($own)$this->db->commit();return $result;}
        catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function audit(?int $userId,string $action,array $metadata,string $ip,string $agent): void {
        $s=$this->db->prepare('INSERT INTO audit_logs(user_id,action,auditable_type,auditable_id,metadata,ip_address,user_agent,created_at,updated_at) VALUES(?,?,"users",?,?,?,?,NOW(),NOW())');
        $s->execute([$userId,$action,$userId,json_encode($metadata,JSON_UNESCAPED_SLASHES),substr($ip,0,45),substr($agent,0,255)]);
    }

    // Both IP and account counters live in MySQL, so a new browser session cannot reset them.
    private function consumeLimit(string $id,string $ip): int {
        $now=time();$limits=[hash('sha256','recovery-ip|'.$ip)=>20,hash('sha256','recovery-account|'.$id)=>5];
        ksort($limits);$rows=[];$retry=0;
        foreach($limits as $key=>$maximum){
            $insert=$this->db->prepare('INSERT INTO password_recovery_limits(key_hash,attempts,window_started_at,updated_at) VALUES(?,0,?,?) ON DUPLICATE KEY UPDATE key_hash=VALUES(key_hash)');
            $insert->execute([$key,date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)]);
            $s=$this->db->prepare('SELECT attempts,window_started_at FROM password_recovery_limits WHERE key_hash=? FOR UPDATE');$s->execute([$key]);$row=$s->fetch();
            $started=strtotime($row['window_started_at']);$attempts=(int)$row['attempts'];
            if($started+self::WINDOW_SECONDS<=$now){$started=$now;$attempts=0;}
            if($attempts>=$maximum)$retry=max($retry,$started+self::WINDOW_SECONDS-$now);
            $rows[$key]=[$attempts,$started];
        }
        if($retry>0)return $retry;
        foreach($rows as $key=>[$attempts,$started]){
            $s=$this->db->prepare('UPDATE password_recovery_limits SET attempts=?,window_started_at=?,updated_at=? WHERE key_hash=?');
            $s->execute([$attempts+1,date('Y-m-d H:i:s',$started),date('Y-m-d H:i:s',$now),$key]);
        }
        return 0;
    }

    public function verifyIdentity(string $id,string $lastName,string $ip,string $agent=''): array {
        $id=trim($id);$lastName=self::normalizeName($lastName);
        $generic='The school ID number and last name do not match, or this account is unavailable.';
        return $this->transaction(function()use($id,$lastName,$ip,$agent,$generic){
            $retry=$this->consumeLimit($id,$ip);
            if($retry)return ['ok'=>false,'error'=>'Too many recovery attempts. Please try again later.','retry_after'=>$retry];
            $s=$this->db->prepare('SELECT id,password,last_name,disabled_at FROM users WHERE id_number=? LIMIT 1');$s->execute([$id]);$user=$s->fetch();
            $valid=$id!==''&&strlen($id)<=64&&$lastName!==''&&mb_strlen($lastName)<=120&&$user&&empty($user['disabled_at'])
                &&hash_equals(self::normalizeName((string)$user['last_name']),$lastName);
            if(!$valid){$this->audit($user?(int)$user['id']:null,'password_recovery_failed',['identity_hash'=>hash('sha256',$id)],$ip,$agent);return ['ok'=>false,'error'=>$generic,'retry_after'=>0];}
            $this->audit((int)$user['id'],'password_recovery_verified',['method'=>'school_id_last_name'],$ip,$agent);
            return ['ok'=>true,'grant'=>['user_id'=>(int)$user['id'],'fingerprint'=>hash('sha256',$user['password']),'expires_at'=>time()+self::GRANT_SECONDS]];
        });
    }

    public static function validGrant(?array $grant): bool {
        return $grant&&(int)($grant['user_id']??0)>0&&(int)($grant['expires_at']??0)>time()
            &&is_string($grant['fingerprint']??null)&&strlen($grant['fingerprint'])===64;
    }

    public function resetPassword(?array $grant,string $password,string $confirmation,string $ip,string $agent=''): array {
        $expired=['ok'=>false,'expired'=>true,'error'=>'Your verification expired or was already used. Verify your school ID and last name again.'];
        if(!self::validGrant($grant))return $expired;
        if(mb_strlen($password,'UTF-8')<8||strlen($password)>72)return ['ok'=>false,'expired'=>false,'error'=>'Use at least 8 characters and no more than 72 bytes for your password.'];
        if(!hash_equals($password,$confirmation))return ['ok'=>false,'expired'=>false,'error'=>'The password confirmation does not match.'];
        return $this->transaction(function()use($grant,$password,$ip,$agent,$expired){
            $s=$this->db->prepare('SELECT id,password,role,disabled_at FROM users WHERE id=? FOR UPDATE');$s->execute([(int)$grant['user_id']]);$user=$s->fetch();
            if(!$user||!empty($user['disabled_at'])||!hash_equals($grant['fingerprint'],hash('sha256',$user['password'])))return $expired;
            if(password_verify($password,$user['password']))return ['ok'=>false,'expired'=>false,'error'=>'Choose a different password from your current password.'];
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $s=$this->db->prepare('UPDATE users SET password=?,must_change_password=0,locked_until=NULL,remember_token=NULL,last_logout_at=NOW(),updated_at=NOW() WHERE id=?');$s->execute([$hash,(int)$user['id']]);
            $s=$this->db->prepare('DELETE FROM sessions WHERE user_id=?');$s->execute([(int)$user['id']]);
            $this->audit((int)$user['id'],'password_reset_self',['method'=>'school_id_last_name','role'=>$user['role']],$ip,$agent);
            return ['ok'=>true];
        });
    }
}
