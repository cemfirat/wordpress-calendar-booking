<?php
namespace Wpcb\VideoMeetings;

final class ZoomProvider extends AbstractBearerProvider {
    public function code(): string { return 'zoom'; }
    public function capabilities(): array { return ['create'=>true,'update'=>true,'delete'=>true]; }

    public function create(array $booking, object $connection): array {
        $token=$this->token($connection);
        if($token==='') return ['ok'=>false,'message'=>'Zoom access token missing.'];
        $cfg=is_array($connection->config ?? null)?$connection->config:[];
        $user=rawurlencode((string)($cfg['user_id'] ?? 'me'));
        $start=new \DateTimeImmutable((string)$booking['slot_start'], new \DateTimeZone('UTC'));
        $end=new \DateTimeImmutable((string)$booking['slot_end'], new \DateTimeZone('UTC'));
        $duration=max(1,(int)ceil(($end->getTimestamp()-$start->getTimestamp())/60));
        $r=$this->request('POST','https://api.zoom.us/v2/users/'.$user.'/meetings',$token,[
            'topic'=>$this->topic($booking),'type'=>2,'start_time'=>$start->format('Y-m-d\TH:i:s\Z'),
            'duration'=>$duration,'timezone'=>'UTC',
        ]);
        if(empty($r['ok'])) return $r;
        $d=$r['data'];
        return ['ok'=>true,'remote_id'=>(string)($d['id']??''),'join_url'=>(string)($d['join_url']??'')];
    }

    public function update(array $meeting, array $booking, object $connection): array {
        $token=$this->token($connection);
        $id=rawurlencode((string)($meeting['remote_id']??''));
        if($token===''||$id==='') return ['ok'=>false,'message'=>'Zoom meeting credentials missing.'];
        $start=new \DateTimeImmutable((string)$booking['slot_start'],new \DateTimeZone('UTC'));
        $end=new \DateTimeImmutable((string)$booking['slot_end'],new \DateTimeZone('UTC'));
        $r=$this->request('PATCH','https://api.zoom.us/v2/meetings/'.$id,$token,[
            'topic'=>$this->topic($booking),'start_time'=>$start->format('Y-m-d\TH:i:s\Z'),
            'duration'=>max(1,(int)ceil(($end->getTimestamp()-$start->getTimestamp())/60)),'timezone'=>'UTC',
        ]);
        return empty($r['ok'])?$r:['ok'=>true,'remote_id'=>(string)$meeting['remote_id'],'join_url'=>(string)$meeting['join_url']];
    }

    public function delete(array $meeting, object $connection): array {
        $token=$this->token($connection); $id=rawurlencode((string)($meeting['remote_id']??''));
        if($token===''||$id==='') return ['ok'=>false,'message'=>'Zoom meeting credentials missing.'];
        $r=$this->request('DELETE','https://api.zoom.us/v2/meetings/'.$id,$token);
        return empty($r['ok'])?$r:['ok'=>true];
    }
}
