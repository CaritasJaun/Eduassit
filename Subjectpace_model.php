<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|------------------------------------------------------------------
|  Subjectpace_model
|------------------------------------------------------------------
|  Keeps the master list of PACEs by  ➜ grade / subject / number
*/
class Subjectpace_model extends CI_Model
{
    /* full library for the table */
public function get_all($grade_filter = [])
{
    $this->db->select('subject_pace.*, subject.name as subject');
    $this->db->from('subject_pace');
    $this->db->join('subject', 'subject.id = subject_pace.subject_id', 'left');

    if (!empty($grade_filter)) {
        $this->db->where_in('subject_pace.grade', $grade_filter);
    }

    $this->db->order_by('grade, subject_id, pace_number');
    return $this->db->get()->result();
}

public function subject_dropdown()
    {
        $list = $this->db->select('id, name')
                         ->from('subject')
                         ->order_by('name', 'asc')
                         ->get()
                         ->result();

        $out = [];
        foreach ($list as $r) {
            $out[$r->id] = $r->name;
        }

        return $out;
    }

    /* insert OR update a single row — returns true if something changed */
public function save($id, $grade, $subject_id, $pace_no)
    {
        $data = [
            'grade'        => $grade,
            'subject_id'   => $subject_id,
            'pace_number'  => $pace_no,
        ];

        if ($id) {
            $this->db->where('id', $id)->update('subject_pace', $data);
            return $this->db->affected_rows() > 0;
        } else {
            // avoid duplicates: unique per grade + subject + pace #
            $exists = $this->db->where($data)->count_all_results('subject_pace');
            if ($exists) return false;

            $this->db->insert('subject_pace', $data);
            return $this->db->affected_rows() > 0;
        }
    }

    /* bulk-generate 12 consecutive PACEs (e.g. 1001-1012) */
    public function bulk_generate($grade, $subject_id, $start_no)
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $no = $start_no + $i;
            $rowData = ['grade' => $grade, 'subject_id' => $subject_id, 'pace_number' => $no];
            $dup = $this->db->where($rowData)->count_all_results('subject_pace');
            if ($dup) continue;
            $rows[] = $rowData;
        }
        if ($rows) $this->db->insert_batch('subject_pace', $rows);
        return count($rows) > 0;
    }

    /* delete one row */
    public function delete($id)
    {
        $this->db->where('id', $id)->delete('subject_pace');
        return $this->db->affected_rows() > 0;
    }
}
