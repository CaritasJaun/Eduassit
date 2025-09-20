<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Pace_model extends CI_Model
{
    /* ============================================================
     * STUDENTS / SUBJECTS
     * ============================================================ */

    /** Return ACTIVE students this user may see (arrays). */
    public function get_all_students()
    {
        $role   = (int) get_loggedin_role_id(); // 1 SA, 2 Admin, 3 Teacher, 4 Accountant, 6 Principal, 8 Receptionist...
        $userID = (int) get_loggedin_user_id();

        // Admin family → all active students
        if (in_array($role, [1,2,4,6,8], true)) {
            return $this->db->select('id, first_name, last_name')
                ->from('student')
                ->where('active', 1)
                ->order_by('first_name', 'asc')
                ->get()->result_array();
        }

        // Teachers → only their classes' students
        if ($role === 3) {
            $classIDs = $this->db->select('class_id')
                ->from('teacher_allocation')
                ->where('teacher_id', $userID)
                ->get()->result_array();

            if (!$classIDs) return [];

            return $this->db->select('stu.id, stu.first_name, stu.last_name')
                ->from('student AS stu')
                ->join('enroll AS en', 'en.student_id = stu.id', 'left')
                ->where_in('en.class_id', array_column($classIDs, 'class_id'))
                ->where('stu.active', 1)
                ->group_by('stu.id')
                ->order_by('stu.first_name', 'asc')
                ->get()->result_array();
        }

        return [];
    }

    /**
     * Enrolled subjects for a learner (by their current class) in **report order**.
     * Prefers subject_assign; falls back to full subject list if no mapping.
     */
    public function get_enrolled_subjects($student_id)
    {
        $student_id = (int) $student_id;

        // Most recent class mapping
        $class = $this->db->select('class_id')
            ->from('enroll')
            ->where('student_id', $student_id)
            ->order_by('id', 'desc')
            ->limit(1)->get()->row_array();

        if ($class && $this->db->table_exists('subject_assign')) {
            return $this->db->select('s.id, s.name, s.subject_code')
                ->from('subject_assign AS sa')
                ->join('subject AS s', 's.id = sa.subject_id', 'left')
                ->where('sa.class_id', (int)$class['class_id'])
                // report order: numeric subject_code, NULLs last, then name
                ->order_by('(s.subject_code IS NULL)', 'asc', false)
                ->order_by('CAST(s.subject_code AS UNSIGNED)', 'asc', false)
                ->order_by('s.name', 'asc')
                ->get()->result_array();
        }

        // Fallback → all subjects in report order
        return $this->get_all_subjects();
    }

    /** All subjects (arrays) in **report order**. */
    public function get_all_subjects()
    {
        return $this->db->select('id, name, subject_code')
            ->from('subject')
            ->order_by('(subject_code IS NULL)', 'asc', false)
            ->order_by('CAST(subject_code AS UNSIGNED)', 'asc', false)
            ->order_by('name', 'asc')
            ->get()->result_array();
    }

    /* ============================================================
     * ORDER PIPELINE / ASSIGNMENTS
     * ============================================================ */

    /**
     * All assignments for a student (optionally by term),
     * ordered by report subject order then the visual slot.
     */
    public function get_student_paces($student_id, $term = null)
    {
        $this->db->select('sap.*, sub.name AS subject_name, sub.subject_code')
            ->from('student_assign_paces AS sap')
            ->join('subject AS sub', 'sub.id = sap.subject_id', 'left')
            ->where('sap.student_id', (int)$student_id);

        if (!empty($term)) $this->db->where('sap.term', $term);

        $this->db
            ->order_by('(sub.subject_code IS NULL)', 'asc', false)
            ->order_by('CAST(sub.subject_code AS UNSIGNED)', 'asc', false)
            ->order_by('COALESCE(sap.slot, sap.slot_index)', 'asc', false);

        return $this->db->get()->result_array();
    }

    /** Mark a bunch of assignment rows completed. */
    public function mark_paces_completed(array $ids)
    {
        if (!$ids) return;
        $this->db->where_in('id', array_map('intval', $ids))
            ->update('student_assign_paces', [
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /* ---------- Available PACE numbers ---------- */

    /** Admin view: all PACE numbers in subject not yet taken by this student. */
    public function get_available_paces_admin($student_id, $subject_id)
    {
        // Order numerically, not lexicographically
        $allNums = array_map('intval', array_column(
            $this->db->select('pace_number')
                ->from('subject_pace')
                ->where('subject_id', (int)$subject_id)
                ->order_by('CAST(pace_number AS UNSIGNED)', 'ASC', false)
                ->get()->result_array(),
            'pace_number'
        ));

        $taken = array_map('intval', array_column(
            $this->db->select('pace_number')
                ->from('student_assign_paces')
                ->where('student_id', (int)$student_id)
                ->where('subject_id', (int)$subject_id)
                ->get()->result_array(),
            'pace_number'
        ));

        return array_values(array_diff($allNums, $taken));
    }

    /** Teacher view: available PACE numbers filtered by learner grade. */
    public function get_available_paces_by_grade($student_id, $subject_id, $gradeNum)
    {
        $student_id = (int)$student_id;
        $subject_id = (int)$subject_id;
        $gradeNum   = (int)$gradeNum;

        // Also grab latest class_id in case the grade column stores class ids
        $classRow = $this->db->select('class_id')
            ->from('enroll')
            ->where('student_id', $student_id)
            ->order_by('id', 'DESC')->limit(1)
            ->get()->row_array();
        $classId = $classRow ? (int)$classRow['class_id'] : 0;

        // Build a tolerant grade match
        $this->db->select('pace_number')
            ->from('subject_pace')
            ->where('subject_id', $subject_id)
            ->group_start()
                ->where('grade', $gradeNum)
                ->or_where('grade', (string)$gradeNum)
                ->or_where('grade', 'Gr ' . $gradeNum)
                ->or_where('grade', 'Grade ' . $gradeNum)
                ->or_where('grade', $classId)
                ->or_where('grade', 'All')
                ->or_where('grade', 'ALL')
                ->or_where('grade', '*')
                ->or_where('grade', '')
                ->or_where('grade IS NULL', null, false)
            ->group_end()
            ->order_by('CAST(pace_number AS UNSIGNED)', 'ASC', false);

        $allNums = array_map('intval', array_column($this->db->get()->result_array(), 'pace_number'));

        // Remove numbers already taken
        $taken = array_map('intval', array_column(
            $this->db->select('pace_number')
                ->from('student_assign_paces')
                ->where('student_id', $student_id)
                ->where('subject_id', $subject_id)
                ->get()->result_array(),
            'pace_number'
        ));

        $available = array_values(array_diff($allNums, $taken));
        sort($available, SORT_NUMERIC);
        return $available;
    }

    /** Helper: next free visual slot (column) in SPC for that subject/year. */
    private function get_next_slot($student_id, $subject_id, $session_id)
    {
        $row = $this->db->select('COALESCE(MAX(COALESCE(slot, slot_index)), 0) AS max_slot', false)
            ->from('student_assign_paces')
            ->where('student_id', (int)$student_id)
            ->where('subject_id', (int)$subject_id)
            ->where('session_id', (int)$session_id)
            ->get()->row_array();

        return (int)($row['max_slot'] ?? 0) + 1;
    }

    /**
     * Start pipeline rows as "assigned" (bulk assign / optional UI).
     * Ensures slots lock left→right; avoids duplicates.
     */
    public function save_pace_assignments($student_id, $subject_id, $term, $pace_nums)
    {
        if (empty($pace_nums)) return;
        if (!is_array($pace_nums)) $pace_nums = [$pace_nums];

        $session = (int) get_session_id();
        $branch  = (int) get_loggedin_branch_id();
        $next    = $this->get_next_slot($student_id, $subject_id, $session);

        foreach ($pace_nums as $num) {
            $num = (int)$num; if ($num <= 0) continue;

            // Exists for this session?
            $existing = $this->db->select('id, slot, slot_index, status')
                ->from('student_assign_paces')
                ->where([
                    'student_id'  => (int)$student_id,
                    'subject_id'  => (int)$subject_id,
                    'pace_number' => $num,
                    'session_id'  => $session,
                ])->get()->row_array();

            if ($existing) {
                $upd = [];
                if ($existing['status'] !== 'assigned') $upd['status'] = 'assigned';

                $curr = (int)($existing['slot'] ?: $existing['slot_index']);
                if ($curr <= 0) {
                    $upd['slot']       = $next;
                    $upd['slot_index'] = $next;
                    $next++;
                }
                if ($upd) $this->db->update('student_assign_paces', $upd, ['id' => (int)$existing['id']]);
                continue;
            }

            // Fresh row
            $this->db->insert('student_assign_paces', [
                'student_id'     => (int)$student_id,
                'subject_id'     => (int)$subject_id,
                'pace_number'    => $num,
                'term'           => $term,
                'status'         => 'assigned',
                'assigned_date'  => date('Y-m-d'),
                'session_id'     => $session,
                'branch_id'      => $branch,
                'attempt_number' => 1,
                'slot'           => $next,
                'slot_index'     => $next,
            ]);
            $next++;
        }
    }

    /** Single row fetch (array). */
    public function get_single_assign($id)
    {
        return $this->db->select('sap.*, stu.first_name, stu.last_name')
            ->from('student_assign_paces AS sap')
            ->join('student AS stu', 'stu.id = sap.student_id', 'left')
            ->where('sap.id', (int)$id)
            ->get()->row_array();
    }

    /* ============================================================
     * SCORING
     * ============================================================ */

    /** Save test score with first/second attempt rules; returns bool. */
    public function save_test_score($assign_id, $score, $remarks = '')
    {
        $assign_id  = (int)$assign_id;
        $score      = (int)$score;
        $assignment = $this->db->get_where('student_assign_paces', ['id' => $assign_id])->row_array();
        if (!$assignment) return false;

        $update = [
            'remarks'     => $remarks,
            'scored_date' => date('Y-m-d'),
        ];

        $first  = $assignment['first_attempt_score'];
        $second = $assignment['second_attempt_score'];

        if (is_null($first)) {
            // First attempt
            $update['first_attempt_score'] = $score;
            $update['attempt_number']      = 1;
            $update['status']              = ($score >= 80) ? 'completed' : 'issued';

            if ($score >= 80) {
                $update['completed_at'] = date('Y-m-d H:i:s');

                if ((int)$assignment['slot'] === 0 && (int)$assignment['slot_index'] === 0) {
                    $slot = $this->get_next_slot((int)$assignment['student_id'], (int)$assignment['subject_id'], (int)$assignment['session_id']);
                    $update['slot'] = $slot;
                    $update['slot_index'] = $slot;
                }
            }
        } elseif (is_null($second)) {
            // Second attempt
            $update['second_attempt_score'] = $score;
            $update['attempt_number']       = 2;

            $final  = max((int)$assignment['first_attempt_score'], $score);
            $status = ($final >= 80) ? 'completed' : 'redo';

            $update['status']        = $status;
            $update['final_score']   = $final;
            $update['final_attempt'] = ($score >= (int)$assignment['first_attempt_score']) ? 'second' : 'first';

            if ($status === 'completed') {
                $update['completed_at'] = date('Y-m-d H:i:s');

                if ((int)$assignment['slot'] === 0 && (int)$assignment['slot_index'] === 0) {
                    $slot = $this->get_next_slot((int)$assignment['student_id'], (int)$assignment['subject_id'], (int)$assignment['session_id']);
                    $update['slot'] = $slot;
                    $update['slot_index'] = $slot;
                }
            }
        } else {
            // > 2 attempts not handled here
            return false;
        }

        $this->db->where('id', $assign_id)->update('student_assign_paces', $update);
        return $this->db->affected_rows() > 0;
    }

    /** Bulk score save (same rules as above); returns bool. */
    public function save_score(array $row)
    {
        if (empty($row['id'])) return false;

        $assign_id  = (int)$row['id'];
        $score      = isset($row['score']) ? (int)$row['score'] : null;
        $remarks    = isset($row['remarks']) ? $row['remarks'] : '';
        $assignment = $this->db->get_where('student_assign_paces', ['id' => $assign_id])->row_array();
        if (!$assignment) return false;

        $update = [
            'remarks'     => $remarks,
            'scored_date' => date('Y-m-d'),
        ];

        $first  = $assignment['first_attempt_score'];
        $second = $assignment['second_attempt_score'];

        if (is_null($first)) {
            $update['first_attempt_score'] = $score;
            $update['attempt_number']      = 1;
            $update['status']              = ($score >= 80) ? 'completed' : 'issued';

            if ($score >= 80) {
                $update['completed_at'] = date('Y-m-d H:i:s');

                if ((int)$assignment['slot'] === 0 && (int)$assignment['slot_index'] === 0) {
                    $slot = $this->get_next_slot((int)$assignment['student_id'], (int)$assignment['subject_id'], (int)$assignment['session_id']);
                    $update['slot'] = $slot;
                    $update['slot_index'] = $slot;
                }
            }
        } elseif (is_null($second)) {
            $update['second_attempt_score'] = $score;
            $update['attempt_number']       = 2;

            $final = max((int)$assignment['first_attempt_score'], $score);
            if ($final >= 80) {
                $update['status']       = 'completed';
                $update['completed_at'] = date('Y-m-d H:i:s');

                if ((int)$assignment['slot'] === 0 && (int)$assignment['slot_index'] === 0) {
                    $slot = $this->get_next_slot((int)$assignment['student_id'], (int)$assignment['subject_id'], (int)$assignment['session_id']);
                    $update['slot'] = $slot;
                    $update['slot_index'] = $slot;
                }
            } else {
                $update['status'] = 'redo';
            }

            $update['final_score']   = $final;
            $update['final_attempt'] = ($score >= (int)$assignment['first_attempt_score']) ? 'second' : 'first';
        } else {
            return false;
        }

        $this->db->where('id', $assign_id)->update('student_assign_paces', $update);
        return $this->db->affected_rows() > 0;
    }

    /** PACEs pending scoring (issued/redo) – arrays. */
    public function get_pending_paces($student_id, $term = null)
    {
        $this->db->select('sap.*, sub.name AS subject_name, sub.subject_code')
            ->from('student_assign_paces AS sap')
            ->join('subject AS sub', 'sub.id = sap.subject_id', 'left')
            ->where('sap.student_id', (int)$student_id)
            ->where_in('sap.status', ['issued', 'redo']);

        if (!empty($term)) $this->db->where('sap.term', $term);

        $this->db
            ->order_by('(sub.subject_code IS NULL)', 'asc', false)
            ->order_by('CAST(sub.subject_code AS UNSIGNED)', 'asc', false)
            ->order_by('COALESCE(sap.slot, sap.slot_index)', 'asc', false);

        return $this->db->get()->result_array();
    }

    /* ============================================================
     * SINGLE ASSIGN (LEGACY HELPER)
     * ============================================================ */

    /** Ensure a specific PACE is assigned; lock slot if needed. */
    public function assign_single_pace($student_id, $subject_id, $term, $pace_no)
    {
        $session = (int) get_session_id();
        $branch  = (int) get_loggedin_branch_id();
        $pace_no = (int) $pace_no;

        // Does it already exist for this session?
        $row = $this->db->select('id, slot, slot_index, status')
            ->from('student_assign_paces')
            ->where([
                'student_id'  => (int)$student_id,
                'subject_id'  => (int)$subject_id,
                'pace_number' => $pace_no,
                'session_id'  => $session,
            ])->get()->row_array();

        // Next free slot
        $slot = $this->get_next_slot($student_id, $subject_id, $session);

        if ($row) {
            $current = (int)($row['slot'] ?: $row['slot_index']);
            $upd = [
                'status'        => 'assigned',
                'assigned_date' => date('Y-m-d'),
            ];
            if ($current <= 0) {
                $upd['slot'] = $slot;
                $upd['slot_index'] = $slot;
            } else {
                $slot = $current; // keep existing
            }
            $this->db->update('student_assign_paces', $upd, ['id' => (int)$row['id']]);
            return ['id' => (int)$row['id'], 'slot' => $slot];
        }

        // Fresh row
        $this->db->insert('student_assign_paces', [
            'student_id'     => (int)$student_id,
            'subject_id'     => (int)$subject_id,
            'pace_number'    => (int)$pace_no,
            'term'           => $term,
            'status'         => 'assigned',
            'assigned_date'  => date('Y-m-d'),
            'session_id'     => $session,
            'branch_id'      => $branch,
            'attempt_number' => 1,
            'slot'           => $slot,
            'slot_index'     => $slot,
        ]);

        return ['id' => (int)$this->db->insert_id(), 'slot' => $slot];
    }

    // NEW: quick stock lookup for a Subject PACE via its stock_code (SKU)
    public function has_stock_for_subject_pace($subject_pace_id, $qty = 1)
    {
        $sp = $this->db->select('sp.stock_code')
            ->from('subject_pace as sp')
            ->where('sp.id', (int)$subject_pace_id)
            ->get()->row_array();

        if (!$sp || empty($sp['stock_code'])) {
            return false; // no mapping set yet
        }

        // NOTE: change 'products' to 'product' if your table is singular.
        $product = $this->db->select('id, code, (qty) as on_hand')
            ->from('products')
            ->where('code', $sp['stock_code'])
            ->get()->row_array();

        return (int)($product['on_hand'] ?? 0) >= (int)$qty;
    }

    public function get_subject_paces_with_stock($subject_id, $grade)
    {
        return $this->db->select('sp.*, p.id AS product_id, p.code AS product_code, p.available_stock, p.sales_price')
            ->from('subject_pace AS sp')
            ->join('product AS p', 'p.id = sp.product_id', 'left')
            ->where('sp.subject_id', (int)$subject_id)
            ->where('sp.grade', (int)$grade)
            ->order_by('CAST(sp.pace_number AS UNSIGNED)', 'ASC', false)
            ->get()->result_array();
    }

    // === NEW: helpers for student-assigned subjects & order filtering ===

    /** Latest grade (class_id) for a student. */
    private function get_student_grade_id($student_id)
    {
        $row = $this->db->select('class_id')
            ->from('enroll')
            ->where('student_id', (int)$student_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()->row_array();

        return (int)($row['class_id'] ?? 0);
    }

    /**
     * Subject IDs explicitly assigned to a student by teacher/admin.
     * Supports either student_assigned_subjects.session_id OR .year
     * (both handled if present).
     */
    public function get_assigned_subject_ids_for_student($student_id)
    {
        $ids = [];
        if (!$this->db->table_exists('student_assigned_subjects')) {
            return $ids;
        }

        $this->db->select('subject_id')
            ->from('student_assigned_subjects')
            ->where('student_id', (int)$student_id);

        if ($this->db->field_exists('session_id', 'student_assigned_subjects')) {
            $this->db->where('session_id', (int) get_session_id());
        } elseif ($this->db->field_exists('year', 'student_assigned_subjects')) {
            $this->db->where('year', (int) date('Y'));
        }

        $ids = array_column($this->db->get()->result_array(), 'subject_id');
        return array_map('intval', $ids);
    }

    /**
     * Subjects to show on Order PACEs for a student:
     *  - If teacher/admin has assigned subjects, show only those.
     *  - Else fall back to class-enrolled subjects (get_enrolled_subjects).
     * Sorted by report order.
     */
    public function get_order_subjects_for_student($student_id)
    {
        $student_id = (int)$student_id;
        $grade_id   = $this->get_student_grade_id($student_id);
        if ($grade_id <= 0) return [];

        $assigned = $this->get_assigned_subject_ids_for_student($student_id);
        if (!empty($assigned)) {
            return $this->db->select('s.id, s.name, s.subject_code')
                ->from('subject_pace AS sp')
                ->join('subject AS s', 's.id = sp.subject_id', 'inner')
                ->where('sp.grade', $grade_id)
                ->where_in('s.id', $assigned)
                ->group_by('s.id')
                ->order_by('(s.subject_code IS NULL)', 'asc', false)
                ->order_by('CAST(s.subject_code AS UNSIGNED)', 'asc', false)
                ->order_by('s.name', 'asc')
                ->get()->result_array();
        }

        return $this->get_enrolled_subjects($student_id);
    }

    /** Convenience wrapper for the order UI to get available numbers using student's grade. */
    public function get_available_paces_for_order($student_id, $subject_id)
    {
        $grade_id = $this->get_student_grade_id((int)$student_id);
        if ($grade_id <= 0) return [];
        return $this->get_available_paces_by_grade((int)$student_id, (int)$subject_id, $grade_id);
    }

    // === NEW: Assigned subject IDs for a student (any year/session) ===
    public function get_assigned_subject_ids_for_student_any($student_id)
    {
        $student_id = (int)$student_id;
        if (!$this->db->table_exists('student_assigned_subjects')) {
            return [];
        }
        $rows = $this->db->select('DISTINCT subject_id', false)
            ->from('student_assigned_subjects')
            ->where('student_id', $student_id)
            ->get()->result_array();
        return array_map('intval', array_column($rows, 'subject_id'));
    }

    /* ============================================================
     * >>> NEW: Grade-tolerant helpers for Assign Subjects <<<
     * ============================================================ */

    /** Build tolerant tokens to match subject_pace.grade for a learner. */
    private function build_grade_tokens($student_id)
    {
        $en = $this->db->select('e.class_id, c.name_numeric')
            ->from('enroll e')
            ->join('class c','c.id = e.class_id','left')
            ->where('e.student_id',(int)$student_id)
            ->order_by('e.id','DESC')->limit(1)
            ->get()->row_array();

        $class_id = (int)($en['class_id'] ?? 0);
        $num      = (int)($en['name_numeric'] ?? 0);

        $tokens = [];
        if ($class_id > 0) $tokens[] = (string)$class_id;
        if ($num > 0) {
            $tokens[] = (string)$num;
            $tokens[] = 'Gr '.$num;
            $tokens[] = 'Grade '.$num;
        }
        // Common buckets
        array_push($tokens, 'All', 'ALL', '*', '');

        return [$tokens, $class_id, $num];
    }

    /** Mandatory subject IDs for learner (grade tolerant; with safe fallback). */
    public function get_mandatory_subject_ids_for_student($student_id)
    {
        list($tokens) = $this->build_grade_tokens($student_id);

        $this->db->select('DISTINCT s.id', false)
            ->from('subject_pace sp')
            ->join('subject s','s.id = sp.subject_id','inner')
            ->where('LOWER(s.subject_type)', 'mandatory');

        if (!empty($tokens)) {
            $this->db->group_start();
            foreach ($tokens as $t) {
                if ($t === '') {
                    $this->db->or_where('sp.grade', '');
                    $this->db->or_where('sp.grade IS NULL', null, false);
                } else {
                    $this->db->or_where('sp.grade', $t);
                }
            }
            $this->db->group_end();
        }

        $ids = array_map('intval', array_column($this->db->get()->result_array(), 'id'));

        // Fallback: all mandatory subjects (prevents empty UI)
        if (!$ids) {
            $ids = array_map('intval', array_column(
                $this->db->select('id')->from('subject')
                    ->where('LOWER(subject_type)', 'mandatory')
                    ->get()->result_array(), 'id'
            ));
        }

        return $ids;
    }

    /** Optional subjects (rows) for learner (grade tolerant; with safe fallback). */
    public function get_optional_subjects_for_student($student_id)
    {
        list($tokens) = $this->build_grade_tokens($student_id);

        $this->db->select('DISTINCT s.id, s.name', false)
            ->from('subject_pace sp')
            ->join('subject s','s.id = sp.subject_id','inner')
            ->where('LOWER(s.subject_type) <>', 'mandatory');

        if (!empty($tokens)) {
            $this->db->group_start();
            foreach ($tokens as $t) {
                if ($t === '') {
                    $this->db->or_where('sp.grade', '');
                    $this->db->or_where('sp.grade IS NULL', null, false);
                } else {
                    $this->db->or_where('sp.grade', $t);
                }
            }
            $this->db->group_end();
        }

        $rows = $this->db
            ->order_by('(s.subject_code IS NULL)','asc',false)
            ->order_by('CAST(s.subject_code AS UNSIGNED)','asc',false)
            ->order_by('s.name','asc')
            ->get()->result_array();

        if (!$rows) {
            $rows = $this->db->select('id, name')
                ->from('subject')
                ->where('LOWER(subject_type) <>', 'mandatory')
                ->order_by('(subject_code IS NULL)','asc',false)
                ->order_by('CAST(subject_code AS UNSIGNED)','asc',false)
                ->order_by('name','asc')
                ->get()->result_array();
        }

        return $rows;
    }

/* --------------------------------------------------------
   Can we assign this PACE? Enforce previous pass ≥ 80%
   (accept first_attempt_score OR second_attempt_score OR final_score)
   -------------------------------------------------------- */
public function can_assign_next(int $student_id, int $subject_id, int $pace_no): bool
{
    // First PACE in strand → allow
    $prev = $this->db->select('first_attempt_score, second_attempt_score, final_score')
        ->from('student_assign_paces')
        ->where('student_id',  $student_id)
        ->where('subject_id',  $subject_id)
        ->where('pace_number <', (int)$pace_no)
        ->where('session_id', (int)get_session_id())
        ->order_by('pace_number', 'DESC')
        ->limit(1)
        ->get()->row_array();

    if (!$prev) return true;

    if (isset($prev['final_score']) && $prev['final_score'] !== null && $prev['final_score'] !== '') {
        return (float)$prev['final_score'] >= 80.0;
    }

    $fa = isset($prev['first_attempt_score'])  ? (float)$prev['first_attempt_score']  : -1;
    $sa = isset($prev['second_attempt_score']) ? (float)$prev['second_attempt_score'] : -1;

    return max($fa, $sa) >= 80.0;
}

/* --------------------------------------------------------
   Convenience: is this exact PACE row already ISSUED?
   (Use when legacy single-assign endpoint is used)
   -------------------------------------------------------- */
public function is_issued_to_student(int $student_id, int $subject_id, int $pace_no): bool
{
    return (bool) $this->db->select('id')
        ->from('student_assign_paces')
        ->where([
            'student_id'  => $student_id,
            'subject_id'  => $subject_id,
            'pace_number' => $pace_no,
            'status'      => 'issued',
            'session_id'  => (int)get_session_id(),
        ])
        ->limit(1)->get()->row_array();
}

}
