<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-12 — Internal CRM. All data scoped to the logged-in account; every write validates ownership.
 */
class Crm extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->load->helper('crm');
        $this->load->helper('lead_scoring');
    }

    private function pipeline_id() { return crm_ensure_pipeline($this->uid); }

    private function owns_deal($deal_id)
    {
        $d = $this->db->from('crm_deals')->where('id',(int)$deal_id)->where('user_id',$this->uid)->get()->row_array();
        return $d ?: null;
    }

    public function index()
    {
        $uid = $this->uid; $pid = $this->pipeline_id();
        $data['open_value'] = (float) ($this->db->select('SUM(value) v')->from('crm_deals')->where('user_id',$uid)->where('status','open')->get()->row()->v ?? 0);
        $data['won_month'] = (int) $this->db->from('crm_deals')->where('user_id',$uid)->where('status','won')->where('won_at >=', date('Y-m-01 00:00:00'))->count_all_results();
        $data['tasks_today'] = (int) $this->db->from('crm_activities')->where('user_id',$uid)->where('status','pending')->where('due_date <=', date('Y-m-d 23:59:59'))->count_all_results();
        $data['hot_leads'] = (int) $this->db->from('messenger_bot_subscriber')->where('user_id',$uid)->where('lead_score >=',50)->count_all_results();
        // source breakdown
        $data['by_source'] = $this->db->select('source, COUNT(*) c')->from('crm_deals')->where('user_id',$uid)->group_by('source')->get()->result_array();
        // won/lost last 6 months
        $data['recent'] = $this->db->select("DATE_FORMAT(created_at,'%Y-%m') m, SUM(status='won') won, SUM(status='lost') lost")->from('crm_deals')->where('user_id',$uid)->where('created_at >=', date('Y-m-01', strtotime('-5 months')))->group_by('m')->order_by('m','ASC')->get()->result_array();
        $data['page_title']='CRM Dashboard'; $data['body']='admin/crm/dashboard';
        $this->_viewcontroller($data);
    }

    public function pipeline()
    {
        $pid = $this->pipeline_id();
        $stages = $this->db->from('crm_stages')->where('pipeline_id',$pid)->order_by('position','ASC')->get()->result_array();
        foreach ($stages as $k=>$s) {
            $stages[$k]['deals'] = $this->db->from('crm_deals')->where('user_id',$this->uid)->where('stage_id',$s['id'])->where('status !=','lost')->order_by('updated_at','DESC')->get()->result_array();
        }
        $data['stages'] = $stages;
        $data['page_title']='Pipeline'; $data['body']='admin/crm/pipeline';
        $this->_viewcontroller($data);
    }

    public function deal_save()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int)$this->input->post('id', true);
        $pid = $this->pipeline_id();
        $stage_id = (int)$this->input->post('stage_id', true);
        // validate stage belongs to pipeline
        $st = $this->db->from('crm_stages')->where('id',$stage_id)->where('pipeline_id',$pid)->get()->row_array();
        if (!$st) { $first = $this->db->from('crm_stages')->where('pipeline_id',$pid)->order_by('position','ASC')->get()->row_array(); $stage_id = $first['id']; }
        $fields = array(
            'title'=>strip_tags((string)$this->input->post('title',true)),
            'value'=>(float)$this->input->post('value',true),
            'currency'=>strip_tags((string)$this->input->post('currency',true)) ?: 'USD',
            'contact_name'=>strip_tags((string)$this->input->post('contact_name',true)),
            'contact_email'=>strip_tags((string)$this->input->post('contact_email',true)),
            'contact_phone'=>strip_tags((string)$this->input->post('contact_phone',true)),
            'stage_id'=>$stage_id, 'updated_at'=>date('Y-m-d H:i:s'),
        );
        if ($id > 0 && $this->owns_deal($id)) {
            $this->db->where('id',$id)->where('user_id',$this->uid)->update('crm_deals', $fields);
        } else {
            $fields['user_id']=$this->uid; $fields['pipeline_id']=$pid; $fields['source']='manual'; $fields['status']='open'; $fields['created_at']=date('Y-m-d H:i:s');
            $this->db->insert('crm_deals', $fields); $id = $this->db->insert_id();
            $this->db->insert('crm_deal_timeline', array('deal_id'=>$id,'user_id'=>$this->uid,'action'=>'created (manual)','created_at'=>date('Y-m-d H:i:s')));
        }
        echo json_encode(['status'=>'1','id'=>$id]);
    }

    public function deal_move()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int)$this->input->post('id', true);
        $stage_id = (int)$this->input->post('stage_id', true);
        $deal = $this->owns_deal($id);
        if (!$deal) { echo json_encode(['status'=>'0']); return; }
        $st = $this->db->from('crm_stages')->where('id',$stage_id)->where('pipeline_id',$deal['pipeline_id'])->get()->row_array();
        if (!$st) { echo json_encode(['status'=>'0']); return; }
        $upd = array('stage_id'=>$stage_id,'updated_at'=>date('Y-m-d H:i:s'));
        if ($st['stage_type']==='won') { $upd['status']='won'; $upd['won_at']=date('Y-m-d H:i:s'); }
        elseif ($st['stage_type']==='lost') { $upd['status']='lost'; }
        else { $upd['status']='open'; }
        $this->db->where('id',$id)->update('crm_deals',$upd);
        $this->db->insert('crm_deal_timeline', array('deal_id'=>$id,'user_id'=>$this->uid,'action'=>'moved to '.$st['name'],'created_at'=>date('Y-m-d H:i:s')));
        echo json_encode(['status'=>'1']);
    }

    public function deal_detail($id=0)
    {
        $deal = $this->owns_deal($id);
        if (!$deal) { show_404(); return; }
        $data['deal'] = $deal;
        $data['timeline'] = $this->db->from('crm_deal_timeline')->where('deal_id',$id)->order_by('id','DESC')->get()->result_array();
        $data['stages'] = $this->db->from('crm_stages')->where('pipeline_id',$deal['pipeline_id'])->order_by('position','ASC')->get()->result_array();
        $conv = array(); $orders = array();
        if ($deal['subscriber_id']) {
            $sub = $this->db->from('messenger_bot_subscriber')->where('id',$deal['subscriber_id'])->get()->row_array();
            if ($sub) {
                $conv = $this->db->from('livechat_messages')->where('subscriber_id',$sub['subscribe_id'])->order_by('id','DESC')->limit(50)->get()->result_array();
                $orders = $this->db->from('ecommerce_cart')->where('subscriber_id',$sub['subscribe_id'])->order_by('id','DESC')->limit(20)->get()->result_array();
            }
            $data['subscriber']=$sub;
        }
        $data['conversation']=$conv; $data['orders']=$orders;
        $data['page_title']='Deal: '.$deal['title']; $data['body']='admin/crm/deal_detail';
        $this->_viewcontroller($data);
    }

    public function contacts()
    {
        $data['page_title']='Contacts'; $data['body']='admin/crm/contacts';
        $this->_viewcontroller($data);
    }

    public function contacts_data()
    {
        header('Content-Type: application/json');
        $rows = $this->db->select('id, subscribe_id, first_name, last_name, full_name, email, phone_number, social_media, lead_score, last_subscriber_interaction_time')
            ->from('messenger_bot_subscriber')->where('user_id',$this->uid)->order_by('lead_score','DESC')->limit(200)->get()->result_array();
        echo json_encode(['data'=>$rows]);
    }

    public function create_deal_from_contact()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $sub_id = (int)$this->input->post('subscriber_id', true);
        $sub = $this->db->from('messenger_bot_subscriber')->where('id',$sub_id)->where('user_id',$this->uid)->get()->row_array();
        if (!$sub) { echo json_encode(['status'=>'0']); return; }
        $pid = $this->pipeline_id(); $stage_id = crm_stage_id($pid,'New Lead');
        $name = trim(($sub['first_name'] ?? '').' '.($sub['last_name'] ?? '')) ?: ($sub['full_name'] ?? 'Contact');
        $this->db->insert('crm_deals', array('user_id'=>$this->uid,'pipeline_id'=>$pid,'stage_id'=>$stage_id,'title'=>$name.' deal',
            'subscriber_id'=>$sub_id,'contact_name'=>$name,'contact_email'=>$sub['email'] ?? '','contact_phone'=>$sub['phone_number'] ?? '',
            'source'=>$sub['social_media'] ?? 'manual','status'=>'open','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')));
        echo json_encode(['status'=>'1','id'=>$this->db->insert_id()]);
    }
}
