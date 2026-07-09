<?php
/*
Addon Name: AI Knowledge Base
Unique Name: ai_knowledge_base
Modules:
{
   "342":{
      "bulk_limit_enabled":"0",
      "limit_enabled":"0",
      "extra_text":"",
      "module_name":"Bot - AI Knowledge Base"
   }
}
Project ID: 120
Addon URI: https://bot.cremonkey.com
Author: Creative Monkey
Author URI: https://cremonkey.com
Version: 1.0
Description: Train your AI bot from website URLs and PDF documents.
*/

require_once("application/controllers/Home.php");

class Ai_knowledge_base extends Home
{
    public $addon_data = array();
    public $upload_dir = '';

    public function __construct()
    {
        parent::__construct();

        if ($this->session->userdata('logged_in') != 1) {
            redirect('home/login', 'location');
        }
        $this->member_validity();

        $addon_path = APPPATH . "modules/" . strtolower($this->router->fetch_class()) . "/controllers/" . ucfirst($this->router->fetch_class()) . ".php";
        $this->addon_data = $this->get_addon_data($addon_path);
        $this->user_id = $this->session->userdata('user_id');

        $this->upload_dir = realpath(APPPATH . '../upload/ai_knowledge') ?: APPPATH . '../upload/ai_knowledge';
        if (!is_dir($this->upload_dir)) {
            @mkdir($this->upload_dir, 0755, true);
        }

        $this->load->helper(array('ai_knowledge', 'security'));
        $this->lang->load('ai_knowledge_base', $this->language);
    }

    public function index()
    {
        $data['body'] = 'ai_knowledge_base/index';
        $data['page_title'] = $this->lang->line('AI Knowledge Base');
        $data['page_info'] = $this->basic->get_data('facebook_rx_fb_page_info', array('where' => array('user_id' => $this->user_id, 'bot_enabled' => '1')), array('id', 'page_id', 'page_name'));
        $this->_viewcontroller($data);
    }

    public function source_data()
    {
        $this->ajax_check();
        if ($this->session->userdata('user_type') != 'Admin' && !in_array(342, $this->module_access)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Access Forbidden')));
            exit();
        }

        // DataTables server-side protocol: draw/start/length + order[0][column|dir]
        $draw = isset($_POST['draw']) ? (int) $_POST['draw'] : 1;
        $offset = isset($_POST['start']) ? (int) $_POST['start'] : 0;
        $rows = isset($_POST['length']) ? (int) $_POST['length'] : 10;
        if ($rows < 1) $rows = 10;

        // column indexes must match the view's DataTable columns definition
        $sortable_columns = array(1 => 'id', 2 => 'source_name', 8 => 'created_at');
        $sort = 'id';
        $order = 'DESC';
        if (isset($_POST['order'][0]['column'])) {
            $col_idx = (int) $_POST['order'][0]['column'];
            if (isset($sortable_columns[$col_idx])) $sort = $sortable_columns[$col_idx];
            if (isset($_POST['order'][0]['dir']) && strtoupper($_POST['order'][0]['dir']) === 'ASC') $order = 'ASC';
        }

        $where_simple = array('ai_knowledge_sources.user_id' => $this->user_id);
        $search_page_id = (int) $this->input->post('search_page_id', true);
        if ($search_page_id > 0) {
            $where_simple['ai_knowledge_sources.page_id'] = $search_page_id;
        }
        $search_source_name = trim($this->input->post('search_source_name', true));
        if ($search_source_name !== '') {
            $where_simple['ai_knowledge_sources.source_name like '] = '%' . $search_source_name . '%';
        }
        $where = array('where' => $where_simple);

        $select = 'ai_knowledge_sources.*, facebook_rx_fb_page_info.page_name';
        $join = array('facebook_rx_fb_page_info' => 'facebook_rx_fb_page_info.id=ai_knowledge_sources.page_id,left');
        $info = $this->basic->get_data('ai_knowledge_sources', $where, $select, $join, $rows, $offset, 'ai_knowledge_sources.' . $sort . ' ' . $order);
        $total = $this->basic->get_data('ai_knowledge_sources', $where, 'count(ai_knowledge_sources.id) as total', $join, 1);
        $total_count = isset($total[0]['total']) ? (int) $total[0]['total'] : 0;

        echo json_encode(array(
            'draw' => $draw,
            'recordsTotal' => $total_count,
            'recordsFiltered' => $total_count,
            'data' => $info
        ));
    }

    public function add_source_action()
    {
        $this->ajax_check();
        if ($this->session->userdata('user_type') != 'Admin' && !in_array(342, $this->module_access)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Access Forbidden')));
            exit();
        }

        $source_type = $this->input->post('source_type', true);
        $source_name = trim($this->input->post('source_name', true));

        if (empty($source_name)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Source name is required.')));
            exit();
        }

        $this->db->trans_start();

        $page_id = (int) $this->input->post('page_id', true);
        if ($page_id > 0) {
            $page_check = $this->basic->get_data('facebook_rx_fb_page_info', array('where' => array('id' => $page_id, 'user_id' => $this->user_id)), array('id'), '', 1);
            if (empty($page_check)) {
                $page_id = 0;
            }
        }

        if (!in_array($source_type, array('pdf', 'url', 'md', 'text'))) {
            $source_type = 'url';
        }

        $insert_data = array(
            'user_id' => $this->user_id,
            'page_id' => $page_id > 0 ? $page_id : null,
            'source_type' => $source_type,
            'source_name' => $source_name,
            'status' => 'active',
            'total_chunks' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        );

        $file_path = null;
        $source_url = '';

        if ($source_type === 'pdf') {
            if (empty($_FILES['source_file']['tmp_name'])) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Please upload a PDF file.')));
                exit();
            }

            $tmp = $_FILES['source_file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['source_file']['name'], PATHINFO_EXTENSION));
            $mime = $_FILES['source_file']['type'];

            if ($ext !== 'pdf' || !in_array($mime, array('application/pdf', 'application/octet-stream'))) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Only PDF files are allowed.')));
                exit();
            }

            if ($_FILES['source_file']['size'] > 10 * 1024 * 1024) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('PDF file must be smaller than 10 MB.')));
                exit();
            }

            $filename = 'kb_' . $this->user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $file_path = $this->upload_dir . '/' . $filename;

            if (!@move_uploaded_file($tmp, $file_path)) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Failed to upload PDF file.')));
                exit();
            }

            $insert_data['file_path'] = $file_path;
            $insert_data['source_url'] = $_FILES['source_file']['name'];
            $text = ai_extract_pdf_text($file_path);
        } elseif ($source_type === 'md') {
            if (empty($_FILES['source_file_md']['tmp_name'])) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Please upload a Markdown or text file.')));
                exit();
            }

            $tmp = $_FILES['source_file_md']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['source_file_md']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, array('md', 'markdown', 'txt'))) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Only .md, .markdown or .txt files are allowed.')));
                exit();
            }

            if ($_FILES['source_file_md']['size'] > 5 * 1024 * 1024) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('File must be smaller than 5 MB.')));
                exit();
            }

            $filename = 'kb_' . $this->user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'markdown' ? 'md' : $ext);
            $file_path = $this->upload_dir . '/' . $filename;

            if (!@move_uploaded_file($tmp, $file_path)) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Failed to upload file.')));
                exit();
            }

            $insert_data['file_path'] = $file_path;
            $insert_data['source_url'] = $_FILES['source_file_md']['name'];
            $text = @file_get_contents($file_path);
            if ($text !== false) {
                // normalize encoding artifacts so chunking/search work cleanly
                $text = str_replace(array("\r\n", "\r", "\0"), array("\n", "\n", ''), $text);
                if (substr($text, 0, 3) === "\xEF\xBB\xBF") $text = substr($text, 3);
            }
        } elseif ($source_type === 'text') {
            $text = trim((string) $this->input->post('source_text', false));
            if (mb_strlen($text) < 20) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Please write at least 20 characters of knowledge text.')));
                exit();
            }
            if (mb_strlen($text) > 500000) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Text is too long (max 500,000 characters).')));
                exit();
            }
            $insert_data['source_url'] = null;
        } else {
            $source_url = trim($this->input->post('source_url', true));
            if (empty($source_url) || !filter_var($source_url, FILTER_VALIDATE_URL)) {
                echo json_encode(array('status' => '0', 'message' => $this->lang->line('Please enter a valid URL.')));
                exit();
            }
            $insert_data['source_url'] = $source_url;
            $text = ai_extract_url_text($source_url);
        }

        if ($text === false || empty(trim($text))) {
            if ($file_path && file_exists($file_path)) {
                @unlink($file_path);
            }
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Could not extract text from the source.')));
            exit();
        }

        $this->basic->insert_data('ai_knowledge_sources', $insert_data);
        $source_id = $this->db->insert_id();

        $chunks = ai_chunk_text($text, 1000, 200);
        $chunk_count = 0;
        if (!empty($chunks)) {
            $batch = array();
            foreach ($chunks as $index => $chunk) {
                $batch[] = array(
                    'source_id' => $source_id,
                    'chunk_text' => $chunk,
                    'chunk_order' => $index,
                    'created_at' => date('Y-m-d H:i:s')
                );
                if (count($batch) >= 50) {
                    $this->db->insert_batch('ai_knowledge_chunks', $batch);
                    $chunk_count += count($batch);
                    $batch = array();
                }
            }
            if (!empty($batch)) {
                $this->db->insert_batch('ai_knowledge_chunks', $batch);
                $chunk_count += count($batch);
            }
        }

        $this->basic->update_data('ai_knowledge_sources', array('id' => $source_id, 'user_id' => $this->user_id), array('total_chunks' => $chunk_count));

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            ai_delete_knowledge_chunks($source_id);
            $this->basic->delete_data('ai_knowledge_sources', array('id' => $source_id, 'user_id' => $this->user_id));
            if ($file_path && file_exists($file_path)) {
                @unlink($file_path);
            }
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Database error. Please try again.')));
            exit();
        }

        // SPEC-20: embed the chunks for semantic retrieval. Deliberately after
        // trans_complete() — this makes network calls and must not hold a DB
        // transaction open. A failure here is non-fatal: chunks keep a NULL
        // embedding and remain searchable via FULLTEXT.
        ai_embed_source_chunks($source_id, $this->user_id);

        echo json_encode(array(
            'status' => '1',
            'message' => $this->lang->line('Knowledge source added successfully.') . ' ' . $chunk_count . ' ' . $this->lang->line('chunks created.')
        ));
    }

    public function delete_source_action()
    {
        $this->ajax_check();
        if ($this->session->userdata('user_type') != 'Admin' && !in_array(342, $this->module_access)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Access Forbidden')));
            exit();
        }

        $id = (int) $this->input->post('id', true);
        if (empty($id)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Invalid source ID.')));
            exit();
        }

        $source = $this->basic->get_data('ai_knowledge_sources', array('where' => array('id' => $id, 'user_id' => $this->user_id)), array('id', 'file_path'), '', 1);
        if (empty($source)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Source not found.')));
            exit();
        }

        ai_delete_knowledge_chunks($id);
        $this->basic->delete_data('ai_knowledge_sources', array('id' => $id, 'user_id' => $this->user_id));

        if (!empty($source[0]['file_path']) && file_exists($source[0]['file_path'])) {
            @unlink($source[0]['file_path']);
        }

        echo json_encode(array('status' => '1', 'message' => $this->lang->line('Knowledge source deleted.')));
    }

    public function toggle_status_action()
    {
        $this->ajax_check();
        if ($this->session->userdata('user_type') != 'Admin' && !in_array(342, $this->module_access)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Access Forbidden')));
            exit();
        }

        $id = (int) $this->input->post('id', true);
        $status = $this->input->post('status', true) === 'active' ? 'active' : 'inactive';

        $this->basic->update_data('ai_knowledge_sources', array('id' => $id, 'user_id' => $this->user_id), array('status' => $status, 'updated_at' => date('Y-m-d H:i:s')));

        echo json_encode(array('status' => '1', 'message' => $this->lang->line('Status updated.')));
    }

    public function preview_chunks_action()
    {
        $this->ajax_check();
        if ($this->session->userdata('user_type') != 'Admin' && !in_array(342, $this->module_access)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Access Forbidden')));
            exit();
        }

        $id = (int) $this->input->post('id', true);
        $chunks = $this->basic->get_data('ai_knowledge_chunks', array('where' => array('source_id' => $id)), '', '', 20, 0, 'chunk_order ASC');
        $source = $this->basic->get_data('ai_knowledge_sources', array('where' => array('id' => $id, 'user_id' => $this->user_id)), array('source_name'), '', 1);

        echo json_encode(array(
            'status' => '1',
            'source_name' => isset($source[0]['source_name']) ? $source[0]['source_name'] : '',
            'chunks' => $chunks
        ));
    }

    public function test_search_action()
    {
        $this->ajax_check();
        if ($this->session->userdata('user_type') != 'Admin' && !in_array(342, $this->module_access)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Access Forbidden')));
            exit();
        }

        $query = trim($this->input->post('query', true));
        $page_id = (int) $this->input->post('page_id', true);
        if (empty($query)) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('Enter a test question.')));
            exit();
        }

        $context = ai_get_knowledge_context($this->user_id, $query, $page_id, 5);
        echo json_encode(array(
            'status' => '1',
            'context' => $context
        ));
    }

    public function activate()
    {
        $this->ajax_check();

        $addon_controller_name = ucfirst($this->router->fetch_class());
        $purchase_code = $this->input->post('purchase_code');
        $this->addon_credential_check($purchase_code, strtolower($addon_controller_name));

        $sidebar = array(
            0 => array(
                'name' => 'AI Knowledge Base',
                'url' => 'ai_knowledge_base',
                'icon' => 'fa fa-book',
                'module_access' => '342',
                'child_info' => array('have_child' => '0')
            )
        );

        $sql = array(
            1 => "CREATE TABLE IF NOT EXISTS `ai_knowledge_sources` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `source_type` enum('pdf','url','md','text') NOT NULL DEFAULT 'url',
              `source_name` varchar(255) NOT NULL,
              `source_url` text,
              `file_path` varchar(255) DEFAULT NULL,
              `status` enum('active','inactive') NOT NULL DEFAULT 'active',
              `total_chunks` int(11) NOT NULL DEFAULT '0',
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `user_id_status` (`user_id`,`status`),
              KEY `source_type` (`source_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            2 => "CREATE TABLE IF NOT EXISTS `ai_knowledge_chunks` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `source_id` int(11) NOT NULL,
              `chunk_text` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
              `embedding` blob DEFAULT NULL,
              `embedding_model` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              `chunk_order` int(11) NOT NULL DEFAULT '0',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `source_id_order` (`source_id`,`chunk_order`),
              FULLTEXT KEY `chunk_text_fulltext` (`chunk_text`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        );

        $this->register_addon($addon_controller_name, $sidebar, $sql, $purchase_code);
    }

    public function deactivate()
    {
        $this->ajax_check();
        $addon_controller_name = ucfirst($this->router->fetch_class());
        $this->unregister_addon($addon_controller_name);
    }

    public function delete()
    {
        $this->ajax_check();
        $addon_controller_name = ucfirst($this->router->fetch_class());

        $sql = array(
            1 => "DROP TABLE IF EXISTS `ai_knowledge_sources`;",
            2 => "DROP TABLE IF EXISTS `ai_knowledge_chunks`;"
        );

        $this->delete_addon($addon_controller_name, $sql);
    }
}
