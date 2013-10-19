<?php
/*
Plugin Name: Mytory Markdown
Description: Markdown edit using Dropbox public link. Only support file url in current version.
Author: mytory
Version: 0.9
Author URI: http://mytory.net
*/

class Mytory_Markdown {

    var $error = array(
        'status' => FALSE,
        'msg' => '',
    );

    function Mytory_Markdown() {
        add_filter('the_content', array(&$this, 'apply_markdown'));
        add_action('add_meta_boxes', array(&$this, 'register_meta_box'));
        add_action('save_post', array(&$this, 'update_post'));

        add_action('wp_ajax_mytory_md_update_editor', array(&$this, 'get_post_content_ajax'));

    }

    /**
     * apply markdown
     * @param  array $attr shortcod attributes
     * @return string contents markdown apply
     */
    public function apply_markdown($post_content, $post_id = NULL) {
        global $post;

        if( ! is_single()){
            return $post_content;
        }

        if($post_id){
            $post = get_post($post_id);
        }

        $markdown_path = get_post_meta($post->ID, 'mytory_md_path', TRUE);
        $markdown_path = str_replace('https://', 'http://', $markdown_path);

        if ($this->_need_to_save($markdown_path)) {

            update_post_meta($post->ID, '_mytory_markdown_etag', $this->_get_etag($markdown_path));
            $post_content = $this->_get_post_content($markdown_path);

            if ($this->error['status'] === TRUE) {
                if(current_user_can('edit_posts')){
                    return "<p>{$this->error['msg']}</p>" . $post_content;
                }else{
                    return $post_content;
                }
            }else{
                $postarr = array(
                    'ID' => $post->ID,
                    'post_content' => $post_content,
                );
                wp_update_post($postarr);
            }
        }

        if ($this->error['status'] === TRUE AND current_user_can('edit_posts')) {
            return "<p>{$this->error['msg']}</p>" . $post_content;
        }

        return $post_content;
    }

    /**
     * get html converted from markdown file path.
     * if error occur, return false.
     * @param $markdown_path
     * @return boolean | string
     */
    private function _get_post_content($markdown_path){
        $md_content = $this->_file_get_contents($markdown_path);

        if($md_content === FALSE){
            return FALSE;
        }

        if (!function_exists('Markdown')) {
            include_once 'markdown.php';
        }

        $post_content = Markdown($md_content);
        $post_content = preg_replace('/<h1>(.*)<\/h1>/', '', $post_content);

        return $post_content;
    }

    public function get_post_content_ajax(){
        $post_content = $this->_get_post_content($_REQUEST['md_path']);

        if( ! $post_content){
            $res = array(
                'error' => TRUE,
                'error_msg' => $this->error['msg'],
                'post_content' => 'error',
            );
        }else{
            $res = array(
                'error' => FALSE,
                'error_msg' => '',
                'post_content' => $post_content,
            );
        }
        echo json_encode($res);
        die();
    }

    /**
     * This function use etag in http header.
     * If etag change, need new save.
     * @param  string $url
     * @return boolean
     */
    private function _need_to_save($url) {
        global $post;

        // If not single page, don't connect for prevent time-wasting.
        // return FALSE that means 'no need to save' to print HTML that is saved.
        // 싱글 페이지가 아니라면 굳이 접속해서 시간낭비할 거 없이 
        // 바로 저장된 HTML을 뿌려줄 수 있도록 save할 필요 없다고 신호를 준다.
        if (!is_single()) {
            return FALSE;
        }

        $etag_saved = get_post_meta($post->ID, '_mytory_markdown_etag', TRUE);

        if ($etag_saved) {
            $etag_remote = $this->_get_etag($url);

            // if there is not etag, don't need to save.
            if ($etag_remote === NULL) {
                return FALSE;
            }

            // if etag different each other, need to save
            return ($etag_saved != $etag_remote);
        } else {
            // if no cache, need to save
            return TRUE;
        }
    }

    /**
     * get etag from dropbox url
     * @param  string $url
     * @return string
     */
    private function _get_etag($url) {
        $header = $this->_get_header_from_url($url);
        $header = $this->_http_parse_headers($header);

        if (isset($header['etag'])) {
            return $header['etag'];
        } else {
            return NULL;
        }
    }

    /**
     * get header from url
     * @param  string $url dropbox public url
     * @return string
     */
    private function _get_header_from_url($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, TRUE);
        curl_setopt($curl, CURLOPT_NOBODY, TRUE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
        $header = curl_exec($curl);

        if( ! $this->_check_curl_error($curl)){
            return FALSE;
        }

        return $header;
    }

    /**
     * get contents form url
     * @param  string $url dropbox public url
     * @return string
     */
    private function _file_get_contents($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_NOBODY, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $content = curl_exec($curl);

        if( ! $this->_check_curl_error($curl)){
            return FALSE;
        }

        return $content;
    }

    private function _check_curl_error($curl){
        $curl_info = curl_getinfo($curl);
        if($curl_info['http_code'] != '200'){
            $this->error = array(
                'status' => TRUE,
                'msg' => 'Network Error! HTTP STATUS is ' . $curl_info['http_code'],
            );
            if($curl_info['http_code'] == 0){
                $this->error['msg'] = 'Network Error! Maybe, connection error.';
            }
            return FALSE;
        }
        return TRUE;
    }

    /**
     * parse header to array
     * http://www.php.net/manual/en/function.http-parse-headers.php#112986
     * @param  string $raw_headers
     * @return array
     */
    private function _http_parse_headers($raw_headers) {
        $headers = array();
        $key = ''; // [+]

        foreach (explode("\n", $raw_headers) as $i => $h) {
            $h = explode(':', $h, 2);

            if (isset($h[1])) {
                if (!isset($headers[$h[0]]))
                    $headers[$h[0]] = trim($h[1]);
                elseif (is_array($headers[$h[0]])) {
                    // $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1]))); // [+]
                } else {
                    // $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [+]
                }

                $key = $h[0]; // [+]
            } else // [+]
            { // [+]
                if (substr($h[0], 0, 1) == "\t") // [+]
                $headers[$key] .= "\r\n\t" . trim($h[0]); // [+]
                elseif (!$key) // [+]
                $headers[0] = trim($h[0]);
                trim($h[0]); // [+]
            } // [+]
        }

        return $headers;
    }

    function register_meta_box() {
        add_meta_box(
            'mytory-markdown-path',
            'Markdown File Path',
            array(&$this, 'meta_box_inner')
        );
    }

    function meta_box_inner() {
        $markdown_path = get_post_meta($_GET['post'], 'mytory_md_path', TRUE);
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">URL</th>
                <td>
                    <input type="url" name="mytory_md_path" id="mytory-md-path" class="large-text" value="<?php echo $markdown_path?>">
                </td>
            </tr>
            <tr>
                <th><?_e('Update', 'mytory-markdown')?></th>
                <td>
                    <button type="button" class="button js-update-content"><?_e('Update Editor', 'mytory-markdown')?></button>
                </td>
            </tr>
        </table>
        <script type="text/javascript">
            jQuery(document).ready(function($){
                 $('.js-update-content').click(function(){
                     $.get(wp.ajax.settings.url, {
                         action: 'mytory_md_update_editor',
                         md_path: $('#mytory-md-path').val()
                     }, function(res){
                         if(res.error){
                             alert(res.error_msg);
                         }else{
                             var post_content = res.post_content;

                             // TODO 텍스트모드일 때 업데이트 안 됨.
                             // 처음 나오는 h1은 제목 필드에 넣어 주자.
                             tinymce.activeEditor.setContent(post_content);
                         }
                     }, 'json');
                 });
            });
        </script>
    <?
    }

    function update_post($post_id) {
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // 데이터 저장
        if(isset($_POST['mytory_md_path'])){
            update_post_meta($post_id, 'mytory_md_path', $_POST['mytory_md_path']);
        }
    }


}

$mytory_markdown = new Mytory_Markdown;