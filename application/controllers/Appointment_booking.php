<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-17 — Appointment booking. Admin manages services/availability/appointments;
 * public book/<user_hash> page lets customers self-book. Reminder cron endpoint included.
 */
class Appointment_booking extends Home
{
    public function __construct()
    {
        parent::__construct();
        $seg = $this->uri->segment(2);
        $public = array('book', 'slots', 'submit_booking', 'reminder');
        if (!in_array($seg, $public)) {
            if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
            $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        }
    }

    private function user_hash($user_id) { return substr(md5('ab_'.$user_id.$this->config->item('encryption_key')), 0, 16); }

    public function index()
    {
        $data['services'] = $this->basic->get_data('ab_services', ['where'=>['user_id'=>$this->uid]], '', '', '', '', 'id DESC');
        $data['availability'] = $this->basic->get_data('ab_availability', ['where'=>['user_id'=>$this->uid]], '', '', '', '', 'weekday ASC');
        $data['appointments'] = $this->basic->get_data('ab_appointments', ['where'=>['user_id'=>$this->uid]], '', '', 100, 0, 'starts_at DESC');
        $data['booking_url'] = base_url('appointment_booking/book/'.$this->user_hash($this->uid));
        $data['page_title'] = 'Appointments';
        $data['body'] = 'admin/appointment_booking/index';
        $this->_viewcontroller($data);
    }

    public function save_service()
    {
        $this->csrf_token_check();
        $this->basic->insert_data('ab_services', array(
            'user_id'=>$this->uid, 'name'=>strip_tags((string)$this->input->post('name',true)),
            'duration_min'=>(int)$this->input->post('duration_min',true) ?: 30,
            'price'=>(float)$this->input->post('price',true), 'currency'=>strip_tags((string)$this->input->post('currency',true)) ?: 'USD',
            'status'=>'1', 'created_at'=>date('Y-m-d H:i:s'),
        ));
        redirect('appointment_booking');
    }

    public function delete_service($id=0)
    {
        $this->db->where(['id'=>(int)$id,'user_id'=>$this->uid])->delete('ab_services');
        redirect('appointment_booking');
    }

    public function save_availability()
    {
        $this->csrf_token_check();
        $this->db->where('user_id',$this->uid)->delete('ab_availability');
        $days = $this->input->post('weekday', true);
        $starts = $this->input->post('start_time', true);
        $ends = $this->input->post('end_time', true);
        if (is_array($days)) {
            foreach ($days as $i=>$wd) {
                if (!empty($starts[$i]) && !empty($ends[$i]))
                    $this->basic->insert_data('ab_availability', array('user_id'=>$this->uid,'weekday'=>(int)$wd,'start_time'=>$starts[$i],'end_time'=>$ends[$i]));
            }
        }
        redirect('appointment_booking');
    }

    public function set_status($id=0, $status='')
    {
        $allowed = array('pending','confirmed','cancelled','done');
        if (in_array($status,$allowed)) $this->db->where(['id'=>(int)$id,'user_id'=>$this->uid])->update('ab_appointments', array('status'=>$status));
        redirect('appointment_booking');
    }

    // ---- Public booking ----
    private function resolve_user($hash)
    {
        // find a user whose hash matches (bounded scan of users with services)
        $users = $this->db->select('user_id')->distinct()->from('ab_services')->where('status','1')->get()->result_array();
        foreach ($users as $u) if ($this->user_hash($u['user_id']) === $hash) return (int)$u['user_id'];
        return 0;
    }

    public function book($hash='')
    {
        $uid = $this->resolve_user($hash);
        if (!$uid) { echo 'Invalid booking link.'; return; }
        $data['uid_hash'] = $hash;
        $data['services'] = $this->basic->get_data('ab_services', ['where'=>['user_id'=>$uid,'status'=>'1']]);
        $this->load->view('site/appointment_book', $data);
    }

    public function slots()
    {
        header('Content-Type: application/json');
        $hash = $this->input->get('h'); $uid = $this->resolve_user($hash);
        $service_id = (int)$this->input->get('service_id'); $date = $this->input->get('date');
        if (!$uid || !$service_id || !$date) { echo json_encode(['slots'=>[]]); return; }
        $svc = $this->db->from('ab_services')->where('id',$service_id)->where('user_id',$uid)->get()->row_array();
        if (!$svc) { echo json_encode(['slots'=>[]]); return; }
        $weekday = (int)date('w', strtotime($date));
        $avail = $this->db->from('ab_availability')->where('user_id',$uid)->where('weekday',$weekday)->get()->result_array();
        $dur = (int)$svc['duration_min'];
        $taken = $this->db->from('ab_appointments')->where('user_id',$uid)->where('DATE(starts_at)',$date)->where_in('status',array('pending','confirmed'))->get()->result_array();
        $taken_times = array(); foreach ($taken as $t) $taken_times[date('H:i',strtotime($t['starts_at']))]=true;
        $slots = array();
        foreach ($avail as $a) {
            $t = strtotime($date.' '.$a['start_time']); $end = strtotime($date.' '.$a['end_time']);
            while ($t + $dur*60 <= $end) {
                $hm = date('H:i',$t);
                if (empty($taken_times[$hm]) && $t > time()) $slots[] = $hm;
                $t += $dur*60;
            }
        }
        echo json_encode(['slots'=>$slots]);
    }

    public function submit_booking()
    {
        header('Content-Type: application/json');
        $hash = $this->input->post('h'); $uid = $this->resolve_user($hash);
        $service_id = (int)$this->input->post('service_id');
        $date = $this->input->post('date'); $time = $this->input->post('time');
        $name = strip_tags((string)$this->input->post('name')); $phone = strip_tags((string)$this->input->post('phone')); $email = strip_tags((string)$this->input->post('email'));
        if (!$uid || !$service_id || !$date || !$time || $name==='') { echo json_encode(['status'=>'0','message'=>'Missing fields']); return; }
        $svc = $this->db->from('ab_services')->where('id',$service_id)->where('user_id',$uid)->get()->row_array();
        if (!$svc) { echo json_encode(['status'=>'0','message'=>'Invalid service']); return; }
        $starts = date('Y-m-d H:i:s', strtotime($date.' '.$time));
        $ends = date('Y-m-d H:i:s', strtotime($starts.' +'.$svc['duration_min'].' minutes'));
        // prevent double-book
        $clash = $this->db->from('ab_appointments')->where('user_id',$uid)->where('starts_at',$starts)->where_in('status',array('pending','confirmed'))->count_all_results();
        if ($clash) { echo json_encode(['status'=>'0','message'=>'That slot was just taken.']); return; }
        $key = substr(md5(uniqid('bk',true)),0,16);
        $this->basic->insert_data('ab_appointments', array('user_id'=>$uid,'service_id'=>$service_id,'customer_name'=>$name,'customer_phone'=>$phone,'customer_email'=>$email,'starts_at'=>$starts,'ends_at'=>$ends,'status'=>'pending','booking_key'=>$key,'source'=>'web','created_at'=>date('Y-m-d H:i:s')));
        echo json_encode(['status'=>'1','message'=>'Booked for '.$starts.'. Reference: '.$key]);
    }

    // cron: appointment_booking/reminder/<api_key>
    public function reminder($api_key='')
    {
        // marks upcoming confirmed appointments as reminded (messenger push added when subscriber_id linked)
        $soon = $this->db->from('ab_appointments')->where('status','confirmed')->where('reminded','0')
            ->where('starts_at >=', date('Y-m-d H:i:s'))->where('starts_at <=', date('Y-m-d H:i:s', strtotime('+24 hours')))->get()->result_array();
        $n=0;
        foreach ($soon as $a) { $this->db->where('id',$a['id'])->update('ab_appointments', array('reminded'=>'1')); $n++; }
        echo "appointments reminded: $n";
    }
}
