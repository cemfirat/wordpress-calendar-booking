<?php
namespace Wpcb\VideoMeetings;

final class GoogleMeetProvider extends AbstractBearerProvider {
    public function code(): string { return 'google_meet'; }
    public function capabilities(): array { return ['create'=>true,'update'=>false,'delete'=>false]; }

    public function create(array $booking, object $connection): array {
        $token=$this->token($connection);
        if($token==='') return ['ok'=>false,'message'=>'Google Meet access token missing.'];
        $r=$this->request('POST','https://meet.googleapis.com/v2/spaces',$token,[]);
        if(empty($r['ok'])) return $r;
        $d=$r['data'];
        return ['ok'=>true,'remote_id'=>(string)($d['name']??''),'join_url'=>(string)($d['meetingUri']??'')];
    }
    public function update(array $meeting, array $booking, object $connection): array {
        return ['ok'=>true,'remote_id'=>(string)($meeting['remote_id']??''),'join_url'=>(string)($meeting['join_url']??''),'message'=>'Google Meet space remains valid after reschedule.'];
    }
    public function delete(array $meeting, object $connection): array {
        return ['ok'=>true,'message'=>'Google Meet space has no scheduled event state to delete.'];
    }
}
