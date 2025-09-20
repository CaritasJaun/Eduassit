<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Assign_pace_model extends CI_Model
{
    public function get_all_paces()
    {
        $this->db->select('subject_pace.*, subject.name as subject');
        $this->db->from('subject_pace');
        $this->db->join('subject', 'subject.id = subject_pace.subject_id', 'left');
        $this->db->order_by('grade, subject_id, pace_number');
        return $this->db->get()->result();
    }

    public function get_filtered_paces_by_grade($grade)
    {
        $this->db->select('subject_pace.*, subject.name as subject');
        $this->db->from('subject_pace');
        $this->db->join('subject', 'subject.id = subject_pace.subject_id', 'left');
        $this->db->where('subject_pace.grade', $grade);
        $this->db->order_by('subject_id, pace_number');
        return $this->db->get()->result();
    }
}
