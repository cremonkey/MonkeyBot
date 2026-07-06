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
        $data['new_leads_7d'] = (int) $this->db->from('crm_deals')->where('user_id',$uid)->where('created_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))->count_all_results();
        $data['latest_leads'] = $this->db->select("d.id, d.title, d.contact_name, d.contact_phone, d.contact_email, d.source, d.created_at, s.name AS stage_name, TRIM(CONCAT(COALESCE(m.first_name,''),' ',COALESCE(m.last_name,''))) AS subscriber_name")
            ->from('crm_deals d')
            ->join('crm_stages s','s.id=d.stage_id','left')
            ->join('messenger_bot_subscriber m','m.id=d.subscriber_id','left')
            ->where('d.user_id',$uid)->where('d.status','open')
            ->order_by('d.created_at','DESC')->limit(8)->get()->result_array();
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
        $data['activities'] = $this->db->from('crm_activities')->where('deal_id',$id)->order_by('id','DESC')->get()->result_array();
        $data['timeline'] = $this->db->from('crm_deal_timeline')->where('deal_id',$id)->order_by('id','DESC')->get()->result_array();
        $data['stages'] = $this->db->from('crm_stages')->where('pipeline_id',$deal['pipeline_id'])->order_by('position','ASC')->get()->result_array();
        $conv = array(); $orders = array();
        if ($deal['subscriber_id']) {
            $sub = $this->db->from('messenger_bot_subscriber')->where('id',$deal['subscriber_id'])->get()->row_array();
            if ($sub) {
                $conv = $this->db->from('livechat_messages')->where('user_id',$this->uid)->where('subscriber_id',$sub['subscribe_id'])->order_by('id','DESC')->limit(50)->get()->result_array();
                $orders = $this->db->from('ecommerce_cart')->where('user_id',$this->uid)->where('subscriber_id',$sub['subscribe_id'])->order_by('id','DESC')->limit(20)->get()->result_array();
            }
            $data['subscriber']=$sub;
        }
        $data['conversation']=$conv; $data['orders']=$orders;
        $data['page_title']='Deal: '.$deal['title']; $data['body']='admin/crm/deal_detail';
        $this->_viewcontroller($data);
    }

    public function tasks()
    {
        $data['tasks'] = $this->db->query(
            "SELECT a.id, a.type, a.subject, a.description, a.due_date, a.status, a.created_at, a.deal_id,
                    d.title AS deal_title, d.contact_phone, d.contact_email, d.source,
                    COALESCE(NULLIF(d.contact_name,''), NULLIF(TRIM(CONCAT(COALESCE(m.first_name,''),' ',COALESCE(m.last_name,''))),''), NULLIF(m.full_name,''), d.title) AS customer_name,
                    COALESCE(NULLIF(d.contact_phone,''), NULLIF(m.phone_number,''), '') AS customer_phone,
                    COALESCE(NULLIF(d.contact_email,''), NULLIF(m.email,''), '') AS customer_email
             FROM crm_activities a
             LEFT JOIN crm_deals d ON d.id = a.deal_id
             LEFT JOIN messenger_bot_subscriber m ON m.id = a.subscriber_id
             WHERE a.user_id = ? AND a.status = 'pending'
             ORDER BY a.due_date ASC, a.id ASC", array($this->uid)
        )->result_array();
        $data['page_title']='Tasks'; $data['body']='admin/crm/tasks';
        $this->_viewcontroller($data);
    }

    public function task_complete()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int)$this->input->post('id', true);
        $task = $this->db->from('crm_activities')->where('id',$id)->where('user_id',$this->uid)->get()->row_array();
        if (!$task) { echo json_encode(['status'=>'0']); return; }
        $this->db->where('id',$id)->update('crm_activities', array('status'=>'completed','completed_at'=>date('Y-m-d H:i:s')));
        echo json_encode(['status'=>'1']);
    }

    public function contacts()
    {
        $data['page_title']='Contacts'; $data['body']='admin/crm/contacts';
        $this->_viewcontroller($data);
    }

    public function contacts_data()
    {
        header('Content-Type: application/json');
        // contact info: subscriber row first, else what the AI captured on their latest CRM deal
        $rows = $this->db->query(
            "SELECT m.id, m.subscribe_id, m.first_name, m.last_name, m.full_name,
                    COALESCE(NULLIF(m.email,''), d.contact_email, '') AS email,
                    COALESCE(NULLIF(m.phone_number,''), d.contact_phone, '') AS phone_number,
                    m.social_media, m.lead_score, m.last_subscriber_interaction_time
             FROM messenger_bot_subscriber m
             LEFT JOIN (SELECT subscriber_id, MAX(id) AS mid FROM crm_deals WHERE user_id = ? AND subscriber_id IS NOT NULL AND (COALESCE(contact_phone,'') <> '' OR COALESCE(contact_email,'') <> '') GROUP BY subscriber_id) x ON x.subscriber_id = m.id
             LEFT JOIN crm_deals d ON d.id = x.mid
             WHERE m.user_id = ?
             ORDER BY m.lead_score DESC
             LIMIT 200", array($this->uid, $this->uid)
        )->result_array();
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
        if (!$stage_id) { $first = $this->db->from('crm_stages')->where('pipeline_id',$pid)->order_by('position','ASC')->limit(1)->get()->row_array(); $stage_id = $first['id'] ?? 0; }
        $name = trim(($sub['first_name'] ?? '').' '.($sub['last_name'] ?? '')) ?: ($sub['full_name'] ?? 'Contact');
        $this->db->insert('crm_deals', array('user_id'=>$this->uid,'pipeline_id'=>$pid,'stage_id'=>$stage_id,'title'=>$name.' deal',
            'subscriber_id'=>$sub_id,'contact_name'=>$name,'contact_email'=>$sub['email'] ?? '','contact_phone'=>$sub['phone_number'] ?? '',
            'source'=>$sub['social_media'] ?? 'manual','status'=>'open','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')));
        echo json_encode(['status'=>'1','id'=>$this->db->insert_id()]);
    }
}
