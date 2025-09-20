<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pace_order_workflow_model extends CI_Model
{
    /* ========================= Tables ========================= */
    private $tbl_sap           = 'student_assign_paces';
    private $tbl_subject       = 'subject';
    private $tbl_notifications = 'notifications';
    private $tbl_login_cred    = 'login_credential';
    private $tbl_staff         = 'staff';

    private $tbl_pace_stock    = 'pace_stock';               // branch_id, subject_id, <pace#>, <qty>
    private $tbl_inv           = 'invoices';                 // default; constructor will normalize
    private $tbl_inv_items     = 'invoice_items';            // default; constructor will normalize
    private $tbl_ho_req        = 'head_office_requisitions';
    private $tbl_ho_req_items  = 'head_office_requisition_items';

    /* ========================= Roles ========================== */
    private $role_super_admin  = 1;
    private $role_admin        = 2;
    private $role_teacher      = 3;
    private $role_reception    = 8;

    public function __construct()
    {
        parent::__construct();
        // ensure DB instance is available even if not autoloaded
        if (!isset($this->db)) { $this->load->database(); }

        // Header table: prefer singular 'invoice', then plural, then legacy
        if ($this->db->table_exists('invoice')) {
            $this->tbl_inv = 'invoice';
        } elseif ($this->db->table_exists('invoices')) {
            $this->tbl_inv = 'invoices';
        } elseif ($this->db->table_exists('hs_academy_invoices')) {
            $this->tbl_inv = 'hs_academy_invoices';
        }

        // Item table: prefer new; fallback to legacy.
        if ($this->db->table_exists('invoice_items')) {
            $this->tbl_inv_items = 'invoice_items';
        } elseif ($this->db->table_exists('hs_academy_invoice_items')) {
            $this->tbl_inv_items = 'hs_academy_invoice_items';
        }
    }

    /* ===================== Schema helpers ===================== */

    /** Return first existing column from $candidates on $table, or null. */
    private function resolve_column($table, array $candidates)
    {
        if (!$this->db->table_exists($table)) return null;
        $fields = array_map('strtolower', $this->db->list_fields($table));
        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $fields, true)) return $c;
        }
        return null;
    }

    /** Quick “does this field exist?” check. */
    private function has_col($table, $col)
    {
        if (!$this->db->table_exists($table)) return false;
        return in_array($col, $this->db->list_fields($table), true);
    }

    /** Accept $data but only keep keys that actually exist in $table. */
    private function safe_insert($table, array $data)
    {
        $fields  = $this->db->list_fields($table);
        $payload = array_intersect_key($data, array_flip($fields));
        $this->db->insert($table, $payload);
        return $this->db->insert_id();
    }

    /* ======================== Entry point ===================== */

    /** Call this after each `student_assign_paces` row is created as 'ordered'. */
    public function on_ordered($sap_id)
    {
        $sap = $this->db->get_where($this->tbl_sap, ['id' => (int)$sap_id])->row_array();
        if (!$sap) return;

        $branch_id   = (int)($sap['branch_id']  ?? 0);
        $student_id  = (int)($sap['student_id'] ?? 0);
        $subject_id  = (int)($sap['subject_id'] ?? 0);
        $pace_number = (int)($sap['pace_number']?? 0);
        $session_id  = (int)($sap['session_id'] ?? 0);

        // Subject label (still used for email content)
        $subject       = $this->db->select('name')->get_where($this->tbl_subject, ['id' => $subject_id])->row_array();
        $subject_label = $subject['name'] ?? ('Subject#' . $subject_id);

        // Recipients
        $recipients = $this->get_recipients($branch_id);

        // ===== Invoice first; capture header id =====
        $invoice_id = (int)$this->append_to_hs_invoice($branch_id, $student_id, $session_id, $sap_id, $subject_id, $pace_number);

        // ===== Stock + head office requisition if short (unchanged) =====
        $this->stock_check_and_requisition($branch_id, $subject_id, $pace_number, $sap_id);

        // ===== ONE notification per INVOICE (de-dup by receiver + invoice_id in URL/payload) =====
        if ($invoice_id > 0 && !empty($recipients) && $this->db->table_exists($this->tbl_notifications)) {
            foreach ($recipients as $r) {
                $this->ensure_invoice_notice_for((int)$r['user_id'], $invoice_id, $student_id, $branch_id);
            }
        }

        // Emails — still per PACE (adjust if you want batching)
        $this->send_emails($recipients, $sap_id, $student_id, $subject_label, $pace_number);
    }

    /* =================== Recipients / comms =================== */

    private function get_recipients($branch_id)
    {
        $rows = $this->db->select('lc.user_id, COALESCE(s.email, lc.username) AS email', false)
            ->from($this->tbl_login_cred . ' lc')
            ->join($this->tbl_staff . ' s', 's.id = lc.user_id', 'left')
            ->where_in('lc.role', [$this->role_super_admin, $this->role_admin, $this->role_reception])
            ->where('lc.active', 1)
            ->where('s.branch_id', (int)$branch_id)
            ->get()->result_array();

        $map = [];
        foreach ($rows as $r) {
            if (!empty($r['email'])) {
                $map[(int)$r['user_id']] = [
                    'user_id' => (int)$r['user_id'],
                    'email'   => $r['email'],
                ];
            }
        }
        return array_values($map);
    }

    /** (Legacy, no longer used for per-PACE notices; kept for compatibility) */
    private function create_notifications(array $recipients, $sap_id, $student_id, $subject_label, $pace_number, $branch_id)
    {
        if (empty($recipients) || !$this->db->table_exists($this->tbl_notifications)) return;

        $title = 'PACE Order';
        $msg   = "Student #{$student_id} ordered {$subject_label} PACE {$pace_number}.";
        $url   = site_url('pace/order?focus_sap=' . (int)$sap_id);
        $now   = date('Y-m-d H:i:s');

        foreach ($recipients as $r) {
            $this->safe_insert($this->tbl_notifications, [
                'receiver_id' => (int)$r['user_id'],
                'title'       => $title,
                'message'     => $msg,
                'url'         => $url,
                'branch_id'   => (int)$branch_id,
                'created_at'  => $now,
                'is_read'     => 0,
            ]);
        }
    }

    /** Read school display name + sensible From-email without relying on specific columns. */
    private function get_school_and_from_()
    {
        $host   = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $school = 'EduAssist';
        $from   = 'noreply@' . $host;

        if ($this->db->table_exists('global_settings')) {
            $row = $this->db->limit(1)->get('global_settings')->row_array(); // fetch * to avoid missing-column errors
            if (is_array($row)) {
                $school = $row['school_name']
                       ?? $row['institute_name']
                       ?? $row['system_name']
                       ?? $row['school']
                       ?? $school;

                $from   = $row['email']
                       ?? $row['system_email']
                       ?? $row['smtp_user']
                       ?? $from;
            }
        }
        return [$school, $from];
    }

    private function get_school_display_name()
    {
        $school = null;
        if ($this->db->table_exists('global_settings')) {
            $row = $this->db->limit(1)->get('global_settings')->row_array(); // no column list → safe
            if ($row) {
                $school = $row['school_name']
                       ?? $row['institute_name']
                       ?? $row['system_name']
                       ?? $row['school']
                       ?? null;
            }
        }
        if ($school) return $school;

        $campus = $this->db->select('name')
            ->from('school')
            ->where('id', (int)get_loggedin_branch_id())
            ->get()->row_array();

        return $campus['name'] ?? 'School';
    }

    /** Send emails; silently skip on dev or when SMTP is not configured. */
    private function send_emails(array $recipients, $sap_id, $student_id, $subject_label, $pace_number)
    {
        if (empty($recipients)) return;

        // Skip completely on non-production or when SMTP host missing
        $cfg = $this->db->get_where('email_config', ['id' => 1])->row_array();
        if (strtolower(ENVIRONMENT) !== 'production' || empty($cfg['smtp_host'])) {
            log_message('debug', 'PACE order email skipped (dev / no SMTP). SAP='.$sap_id);
            return;
        }

        list($school, $from) = $this->get_school_and_from_();

        $this->load->library('email');
        $this->email->initialize([
            'protocol'    => 'smtp',
            'smtp_host'   => $cfg['smtp_host'],
            'smtp_user'   => $cfg['smtp_username'],
            'smtp_pass'   => $cfg['smtp_password'],
            'smtp_port'   => (int)($cfg['smtp_port'] ?: 587),
            'smtp_crypto' => ($cfg['encryption'] ?: 'tls'),
            'mailtype'    => 'html',
            'newline'     => "\r\n",
            'crlf'        => "\r\n",
        ]);

        $subject = '[' . $school . '] New PACE Order';
        $body    = nl2br(
            "A new PACE order was placed.\n\n" .
            "Student ID: {$student_id}\n" .
            "PACE: {$subject_label} {$pace_number}\n" .
            "Time: " . date('Y-m-d H:i') . "\n\n" .
            "View: " . site_url('pace/order?focus_sap=' . (int)$sap_id) . "\n"
        );

        foreach ($recipients as $r) {
            if (empty($r['email'])) continue;
            $this->email->clear(true);
            $this->email->from($from, $school);
            $this->email->to($r['email']);
            $this->email->subject($subject);
            $this->email->message($body);
            if (!$this->email->send(false)) {
                // Log only; never echo (prevents breaking the order flow)
                log_message('error', 'PACE order mail failed (SAP '.$sap_id.') — '.$this->email->print_debugger(['headers']));
            }
        }
    }

    /* ======================== Invoice handling ======================== */

    private function append_to_hs_invoice($branch_id, $student_id, $session_id, $sap_id, $subject_id, $pace_number)
    {
        // Is this SAP a REDO row?
        $sapRow  = $this->db->get_where($this->tbl_sap, ['id' => (int)$sap_id])->row_array();
        $is_redo = !empty($sapRow['is_redo']) ? 1 : 0;
        $now     = date('Y-m-d H:i:s');

        // If this SAP is already on any invoice, return that invoice id (idempotent)
        $already = $this->db->select('invoice_id')
            ->get_where($this->tbl_inv_items, ['sap_id' => (int)$sap_id], 1)
            ->row_array();
        if ($already) return (int)$already['invoice_id'];

        // ── Pick / create the invoice header ─────────────────────────────────
        $inv_id = 0;

        if ($is_redo) {
            // REDO: always make a dedicated invoice so it never mixes with normal orders
            $invData = [
                'branch_id'  => (int)$branch_id,
                'student_id' => (int)$student_id,
                'session_id' => (int)$session_id,
                'status'     => 'redo',      // key difference
                'total'      => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($this->has_col($this->tbl_inv, 'is_redo')) {
                $invData['is_redo'] = 1;
            }
            $inv_id = (int)$this->safe_insert($this->tbl_inv, $invData);

        } else {
            // NORMAL order: reuse latest *draft* invoice (but never a redo one)
            $this->db->from($this->tbl_inv)
                ->where('branch_id',  (int)$branch_id)
                ->where('student_id', (int)$student_id)
                ->where('session_id', (int)$session_id)
                ->where('status',     'draft');

            if ($this->has_col($this->tbl_inv, 'is_redo')) {
                $this->db->group_start()
                         ->where('is_redo', 0)
                         ->or_where('is_redo IS NULL', null, false)
                         ->group_end();
            }

            $inv = $this->db->order_by('id','DESC')->limit(1)->get()->row_array();
            if ($inv) {
                $inv_id = (int)$inv['id'];
            } else {
                $inv_id = (int)$this->safe_insert($this->tbl_inv, [
                    'branch_id'  => (int)$branch_id,
                    'student_id' => (int)$student_id,
                    'session_id' => (int)$session_id,
                    'status'     => 'draft',
                    'total'      => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    // Persist non-redo header flag when column exists
                    'is_redo'    => $this->has_col($this->tbl_inv, 'is_redo') ? 0 : null,
                ]);
            }
        }

        if ($inv_id <= 0) return 0;

        // ── Add the line item ────────────────────────────────────────────────
        $paceCol = $this->resolve_column($this->tbl_inv_items, ['pace_number','pace_no','item_no','book_number','number','pace']) ?: 'pace_number';
        $qtyCol  = $this->resolve_column($this->tbl_inv_items, ['qty','quantity','qty_ordered']) ?: 'qty';
        $upCol   = $this->resolve_column($this->tbl_inv_items, ['unit_price','price','unit']) ?: 'unit_price';
        $ltCol   = $this->resolve_column($this->tbl_inv_items, ['line_total','total','line']) ?: 'line_total';
        $noteCol = $this->resolve_column($this->tbl_inv_items, ['description','notes','note','label','item_name','title']);

        $unit_price = $this->guess_price($subject_id, $pace_number, $branch_id, $session_id);
        $line_total = $unit_price * 1;

        $payload = [
            'invoice_id' => $inv_id,
            'sap_id'     => (int)$sap_id,
            'subject_id' => (int)$subject_id,
            $paceCol     => (int)$pace_number,
            $qtyCol      => 1,
            $upCol       => $unit_price,
            $ltCol       => $line_total,
            'is_redo'    => $is_redo, // harmless if column doesn't exist
        ];
        if ($is_redo && $noteCol) {
            $subject = $this->db->select('name')->get_where($this->tbl_subject, ['id' => (int)$subject_id])->row('name');
            $payload[$noteCol] = trim(($subject ?: 'Subject') . " PACE {$pace_number} [REDO]");
        }

        $this->safe_insert($this->tbl_inv_items, $payload);

        // ── Update invoice total ─────────────────────────────────────────────
        $sum = $this->db->select_sum($ltCol, 't')
            ->get_where($this->tbl_inv_items, ['invoice_id' => $inv_id])->row_array();

        $this->db->where('id', $inv_id)->update($this->tbl_inv, [
            'total'      => (float)($sum['t'] ?? 0),
            'updated_at' => $now,
        ]);

        // return for upstream (for per-invoice notifications)
        return (int)$inv_id;
    }

    /* ================== Stock / HO requisitions ================== */

    private function stock_check_and_requisition($branch_id, $subject_id, $pace_number, $sap_id)
    {
        // Column discovery on pace_stock
        $paceColStock = $this->resolve_column($this->tbl_pace_stock, ['pace_number','pace_no','book_number','number','item_no','pace']);
        $qtyColStock  = $this->resolve_column($this->tbl_pace_stock, ['qty','quantity','stock_qty']);

        if (!$this->db->table_exists($this->tbl_pace_stock) || !$paceColStock) return;

        $row = $this->db->get_where($this->tbl_pace_stock, [
            'branch_id'   => (int)$branch_id,
            'subject_id'  => (int)$subject_id,
            $paceColStock => (int)$pace_number,
        ])->row_array();

        $have = (int)($row[$qtyColStock ?? 'qty'] ?? 0);
        $need = 1;

        if ($have >= $need) return; // enough stock

        // Find or create today's pending requisition
        $today = date('Y-m-d');
        $req = $this->db->select('id')
            ->from($this->tbl_ho_req)
            ->where('branch_id', (int)$branch_id)
            ->where('status', 'pending')
            ->like('created_at', $today, 'after')
            ->get()->row_array();

        $req_id = $req ? (int)$req['id'] : $this->safe_insert($this->tbl_ho_req, [
            'order_id'   => null,
            'branch_id'  => (int)$branch_id,
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Column discovery on requisition items
        $paceColReq = $this->resolve_column($this->tbl_ho_req_items, ['pace_number','pace_no','book_number','number','item_no','pace']);
        $qtyColReq  = $this->resolve_column($this->tbl_ho_req_items, ['qty','quantity','qty_ordered']);
        if (!$this->db->table_exists($this->tbl_ho_req_items) || !$paceColReq) return;

        $short = max(0, $need - $have);

        $payload = [
            'requisition_id' => $req_id,
            'subject_id'     => (int)$subject_id,
            $paceColReq      => (int)$pace_number,
            ($qtyColReq ?: 'qty') => (int)$short,
        ];
        if ($this->has_col($this->tbl_ho_req_items, 'sap_id')) {
            $payload['sap_id'] = (int)$sap_id;
        }

        $this->safe_insert($this->tbl_ho_req_items, $payload);
    }

    /* ===================== Price resolver ====================== */

    private function guess_price($subject_id, $pace_number, $branch_id, $session_id)
    {
        // Only read if the column exists; otherwise return 0.00 without querying a non-existent field
        if ($this->db->table_exists('global_settings') &&
            $this->db->field_exists('pace_default_price', 'global_settings')) {
            $row = $this->db->limit(1)->get('global_settings')->row_array();
            if (isset($row['pace_default_price']) && $row['pace_default_price'] !== '') {
                return (float)$row['pace_default_price'];
            }
        }
        return 0.00;
    }

    /* ===================== Notification helpers ====================== */

    /** Ensure exactly one unread notification per receiver *and* invoice. Upsert the message with item count. */
    private function ensure_invoice_notice_for(int $receiver_id, int $invoice_id, int $student_id, int $branch_id): void
    {
        if (!$this->db->table_exists($this->tbl_notifications)) return;

        $now   = date('Y-m-d H:i:s');
        $count = $this->count_invoice_items($invoice_id);

        $title = 'PACE Order Batch';
        $msg   = "Student #{$student_id} ordered {$count} PACE(s) in this invoice.";
        $url   = site_url('pace/order?invoice_id=' . $invoice_id);

        // Try find existing (unread) by receiver + url match (using invoice_id)
        $existing = $this->db
            ->where('receiver_id', $receiver_id)
            ->like('url', 'invoice_id='.$invoice_id, 'both')
            ->where('is_read', 0)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get($this->tbl_notifications)
            ->row_array();

        if ($existing) {
            $upd = ['message' => $msg];
            if ($this->has_col($this->tbl_notifications, 'updated_at')) {
                $upd['updated_at'] = $now;
            }
            $this->db->where('id', (int)$existing['id'])->update($this->tbl_notifications, $upd);
            return;
        }

        // Insert a new one
        $payload = [
            'receiver_id' => $receiver_id,
            'title'       => $title,
            'message'     => $msg,
            'url'         => $url,
            'branch_id'   => $branch_id,
            'created_at'  => $now,
            'is_read'     => 0,
        ];
        $this->safe_insert($this->tbl_notifications, $payload);
    }

    /** Count lines on an invoice (used for the summary text). */
    private function count_invoice_items(int $invoice_id): int
    {
        if (!$this->db->table_exists($this->tbl_inv_items)) return 0;
        return (int)$this->db->where('invoice_id', $invoice_id)->count_all_results($this->tbl_inv_items);
    }
/** Mark an invoice item as assigned to a specific SAP row (no-ops if column missing). */
public function mark_invoice_item_assigned($invoice_item_id, $sap_id)
{
    if (!$this->has_col($this->tbl_inv_items, 'assigned_id')) return false;
    return $this->db->where('id', (int)$invoice_item_id)
                    ->update($this->tbl_inv_items, ['assigned_id' => (int)$sap_id]);
}

}
