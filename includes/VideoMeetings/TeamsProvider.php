<?php
namespace Wpcb\VideoMeetings;

final class TeamsProvider extends AbstractBearerProvider {
    public function code(): string { return 'microsoft_teams'; }
    public function capabilities(): array { return ['create'=>true,'update'=>true,'delete'=>true]; }

    public function create(array $booking, object $connection): array {
        $token=$this->token($connection);
        if($token==='') return ['ok'=>false,'message'=>'Microsoft Teams access token missing.'];
        $cfg=is_array($connection->config ?? null)?$connection->config:[];
        $user=trim((string)($cfg['user_id']??''));
        $base=$user!==''?'https://graph.microsoft.com/v1.0/users/'.rawurlencode($user).'/onlineMeetings':'https://graph.microsoft.com/v1.0/me/onlineMeetings';
        $r=$this->request('POST',$base,$token,[
            'startDateTime'=>(new \DateTimeImmutable((string)$booking['slot_start'],new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'endDateTime'=>(new \DateTimeImmutable((string)$booking['slot_end'],new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'subject'=>$this->topic($booking),
        ]);
        if(empty($r['ok'])) return $r;
        $d=$r['data'];
        return ['ok'=>true,'remote_id'=>(string)($d['id']??''),'join_url'=>(string)($d['joinWebUrl']??'')];
    }

    private function url(object $connection,string $id): string {
        $cfg=is_array($connection->config ?? null)?$connection->config:[];
        $user=trim((string)($cfg['user_id']??''));
        $base=$user!==''?'https://graph.microsoft.com/v1.0/users/'.rawurlencode($user).'/onlineMeetings':'https://graph.microsoft.com/v1.0/me/onlineMeetings';
        return $base.'/'.rawurlencode($id);
    }

    public function update(array $meeting, array $booking, object $connection): array {
        $token=$this->token($connection); $id=(string)($meeting['remote_id']??'');
        if($token===''||$id==='') return ['ok'=>false,'message'=>'Microsoft Teams meeting credentials missing.'];
        $r=$this->request('PATCH',$this->url($connection,$id),$token,[
            'startDateTime'=>(new \DateTimeImmutable((string)$booking['slot_start'],new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'endDateTime'=>(new \DateTimeImmutable((string)$booking['slot_end'],new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'subject'=>$this->topic($booking),
        ]);
        return empty($r['ok'])?$r:['ok'=>true,'remote_id'=>$id,'join_url'=>(string)($meeting['join_url']??'')];
    }

    public function delete(array $meeting, object $connection): array {
        $token=$this->token($connection); $id=(string)($meeting['remote_id']??'');
        if($token===''||$id==='') return ['ok'=>false,'message'=>'Microsoft Teams meeting credentials missing.'];
        $r=$this->request('DELETE',$this->url($connection,$id),$token);
        return empty($r['ok'])?$r:['ok'=>true];
    }
}
