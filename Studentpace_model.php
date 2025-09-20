<?php
class Studentpace_model extends CI_Model {

    public function assign_pace($data) {
        $this->db->insert('student_assign_pace', $data);
    }
}
