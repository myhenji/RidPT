<?php
/**
 * Created by PhpStorm.
 * User: Rhilip
 * Date: 2018/12/21
 * Time: 21:45
 */

namespace apps\models\form\Torrents;

use apps\models\Torrent;

use Rid\Validators\Validator;

use Rid\Bencode\Bencode;
use Rid\Bencode\ParseErrorException;

class UploadForm extends Validator
{

    public $id = 0;

    /**  @var \Rid\Http\UploadFile */
    public $file;
    /**  @var \Rid\Http\UploadFile */
    public $nfo;

    public $category;
    public $title;
    public $subtitle = '';
    public $links;
    public $descr;

    public $anonymous = 0;  // If user upload this torrent Anonymous
    public $hr = 0;  // If This torrent require hr check

    // Quality
    public $audio = 0; /* 0 is default value. */
    public $codec = 0;
    public $medium = 0;
    public $resolution = 0;

    public $team = 0;

    public $tags;

    private $info_hash; // the value of sha1($this->$torrent_dict['info'])

    private $status = 'confirmed';

    private $torrent_dict;
    private $torrent_name;    // the $torrent_dict['info']['name'] field
    private $torrent_list = [];  // the file list like ["filename" => "example.txt" , "size" => 12345]
    private $torrent_structure;  // JSON encode string
    private $torrent_type = 'single'; // only in ['single','multi']
    private $torrent_size = 0;  // the count of torrent's content size

    const TORRENT_TYPE_SINGLE = 'single';
    const TORRENT_TYPE_MULTI = 'multi';

    const TORRENT_STATUS_DELETED = 'deleted';
    const TORRENT_STATUS_BANNED = 'banned';
    const TORRENT_STATUS_PENDING = 'pending';
    const TORRENT_STATUS_CONFIRMED = 'confirmed';

    public static function defaultData()
    {
        return [
            'subtitle' => '',
            'anonymous' => 0, 'hr' => 0,
            'audio' => 0, 'codec' => 0, 'medium' => 0, 'resolution' => 0,
            'team' => 0,
            'tags' => ''
        ];
    }

    public static function inputRules()
    {
        $categories_id_list = array_map(function ($cat) {
            return $cat['id'];
        }, app()->site::ruleCanUsedCategory());

        $rules = [
            'title' => 'required',
            'file' => [
                ['required'],
                ['Upload\Required'],
                ['Upload\Extension', ['allowed' => 'torrent']],
                ['Upload\Size', ['size' => config('upload.max_torrent_file_size') . 'B']]
            ],
            'category' => [
                ['required'], ['Integer'],
                ['InList', ['list' => $categories_id_list]]
            ],
            'descr' => 'required',
        ];

        if (config('torrent_upload.enable_upload_nfo') &&  // Enable nfo upload
            app()->site->getCurUser()->isPrivilege('upload_nfo_file') &&  // This user can upload nfo
            app()->request->post('nfo')  // Nfo file upload
        ) {
            $rules['nfo'] = [
                ['Upload\Extension', ['allowed' => ['nfo', 'txt']]],
                ['Upload\Size', ['size' => config('upload.max_nfo_file_size') . 'B']]
            ];
        }

        // Add Quality Valid
        foreach (app()->site::getQualityTableList() as $quality => $title) {
            $quality_id_list = [0];
            // IF enabled this quality field , then load it value list from setting
            // Else we just allow the default value 0 to prevent cheating
            if (config('torrent_upload.enable_quality_' . $quality)) {
                $quality_id_list += array_map(function ($cat) {
                    return $cat['id'];
                }, app()->site::ruleQuality($quality));
            }

            $rules[$quality] = [
                ['Integer'],
                ['InList', ['list' => $quality_id_list]]
            ];
        }

        // Add Team id Valid
        $team_id_list = [0];
        if (config('torrent_upload.enable_teams')) {
            $team_id_list += array_map(function ($team) {
                return $team['id'];
            }, app()->site::ruleCanUsedTeam());
        }

        $rules['team'] = [
            ['Integer'],
            ['InList', ['list' => $team_id_list]]
        ];

        // Add Flag Valid
        // Notice: we don't valid if user have privilege to use this value,
        // Un privilege flag will be rewrite in rewriteFlags() when call flush()
        if (config('torrent_upload.enable_anonymous')) {
            $rules['uplver'] = [
                ['InList', ['list' => [0, 1]]]
            ];
        }
        if (config('torrent_upload.enable_hr')) {
            $rules['hr'] = [
                ['InList', ['list' => [0, 1]]]
            ];
        }

        return $rules;
    }

    public static function callbackRules()
    {
        return ['isValidTorrent'];
    }

    protected function isValidTorrent()
    {
        try {
            $this->torrent_dict = Bencode::load($this->getData('file')->tmpName);
            $info = $this->checkTorrentDict($this->torrent_dict, 'info');
            if ($info) {
                $this->checkTorrentDict($info, 'piece length', 'integer');  // Only Check without use

                $dname = $this->checkTorrentDict($info, 'name', 'string');
                $pieces = $this->checkTorrentDict($info, 'pieces', 'string');

                if (strlen($pieces) % 20 != 0) throw new ParseErrorException('std_invalid_pieces');

                if (isset($info['length'])) {
                    $this->torrent_size = $info['length'];
                    $this->torrent_list[] = ['filename' => $dname, 'size' => $info['length']];
                    $this->torrent_type = 'single';
                } else {
                    $f_list = $this->checkTorrentDict($info, 'files', 'array');
                    if (!isset($f_list)) throw new ParseErrorException('std_missing_length_and_files');
                    if (!count($f_list)) throw new ParseErrorException('no files');

                    $this->torrent_size = 0;
                    foreach ($f_list as $fn) {
                        $ll = $this->checkTorrentDict($fn, 'length', 'integer');
                        $path_key = isset($fn['path.utf-8']) ? 'path.utf-8' : 'path';
                        $ff = $this->checkTorrentDict($fn, $path_key, 'array');  // 'list' or you can say 'indexed_array'
                        $this->torrent_size += $ll;
                        $ffa = [];
                        foreach ($ff as $ffe) {
                            if (!is_string($ffe)) throw new ParseErrorException('std_filename_errors');
                            $ffa[] = $ffe;
                        }
                        if (!count($ffa)) throw new ParseErrorException('std_filename_errors');
                        $ffe = implode("/", $ffa);
                        // TODO use regex to check this filename is valid or not
                        $this->torrent_list[] = ['filename' => $ffe, 'size' => $ll];
                    }
                    $this->torrent_type = 'multi';
                }
            }
        } catch (ParseErrorException $e) {
            // FIXME Fix message of ParseErrorException
            $this->buildCallbackFailMsg('Bencode', $e->getMessage());
            return;
        }

        $this->torrent_name = $info['name'];
        $this->torrent_structure = $this->getFileTree();
    }

    public function makePrivateTorrent()
    {
        $this->torrent_dict['announce'] = "https://" . config("base.site_tracker_url") . "/announce";

        // Remove un-need field in private torrents
        unset($this->torrent_dict['announce-list']); // remove multi-tracker capability
        unset($this->torrent_dict['nodes']); // remove cached peers (Bitcomet & Azareus)

        // Some other change if you need
        //$this->torrent_dict['commit'] = "";

        /**
         * The following line requires uploader to re-download torrents after uploading **Since info_hash change**
         * even the torrent is set as private and with uploader's passkey in it.
         */

        // Clean The `info` dict
        $allowed_keys = [
            'files', 'name', 'piece length', 'pieces', 'private', 'length',
            'name.utf8', 'name.utf-8', 'md5sum', 'sha1', 'source',
            'file-duration', 'file-media', 'profiles'
        ];
        foreach ($this->torrent_dict['info'] as $key => $value) {
            if (!in_array($key, $allowed_keys)) {
                unset($this->torrent_dict['info'][$key]);
            }
        }

        // Make it private and unique by add our source flag
        $this->torrent_dict['info']['private'] = 1;  // add private tracker flag
        $this->torrent_dict['info']['source'] = "Powered by [" . config("base.site_url") . "] " . config("base.site_name");

        // Get info_hash on new torrent content dict['info']
        $this->info_hash = pack("H*", sha1(Bencode::encode($this->torrent_dict['info'])));
    }

    /**
     * @throws \Exception
     */
    public function flush()
    {
        $this->makePrivateTorrent();
        $this->rewriteFlags();

        // Check if this torrent is exist or not before insert.
        $count = app()->pdo->createCommand('SELECT COUNT(*) FROM torrents WHERE info_hash = :info_hash')->bindParams([
            'info_hash' => $this->info_hash
        ])->queryScalar();
        if ($count > 0) throw new \Exception('std_torrent_existed');

        $nfo_blob = '';
        if (isset($this->nfo)) {  // FIXME it seem always be true ???
            $nfo_blob = $this->nfo->getFileContent();
        }

        $this->determineTorrentStatus();
        app()->pdo->beginTransaction();
        try {
            app()->pdo->createCommand('INSERT INTO `torrents` (`owner_id`,`info_hash`,`status`,`added_at`,`title`,`subtitle`,`category`,`filename`,`torrent_name`,`torrent_type`,`torrent_size`,`torrent_structure`,`quality_audio`,`quality_codec`,`quality_medium`,`quality_resolution`,`team`,`descr`,`nfo`,`uplver`,`hr`) 
VALUES (:owner_id,:info_hash,:status,CURRENT_TIMESTAMP,:title,:subtitle,:category,:filename,:torrent_name,:torrent_type,:torrent_size,:torrent_structure,:quality_audio, :quality_codec, :quality_medium, :quality_resolution,:team, :descr,:nfo ,:uplver, :hr)')->bindParams([
                'owner_id' => app()->site->getCurUser()->getId(),
                'info_hash' => $this->info_hash,
                'status' => $this->status,
                'title' => $this->title, 'subtitle' => $this->subtitle,
                'category' => $this->category,
                'filename' => $this->file->getBaseName(),
                'torrent_name' => $this->torrent_name, 'torrent_type' => $this->torrent_type,
                'torrent_size' => $this->torrent_size, 'torrent_structure' => $this->torrent_structure,
                'quality_audio' => $this->audio, 'quality_codec' => $this->codec,
                'quality_medium' => $this->medium, 'quality_resolution' => $this->resolution,
                'team' => $this->team,
                'descr' => $this->descr,
                'nfo' => $nfo_blob,
                'uplver' => $this->anonymous, 'hr' => $this->hr
            ])->execute();
            $this->id = app()->pdo->getLastInsertId();

            if (config('torrent_upload.enable_tags')) $this->insertTags();
            $this->getExternalLinkInfo();
            $this->setBuff();

            // Save this torrent
            $dump_status = Bencode::dump(Torrent::TorrentFileLoc($this->id), $this->torrent_dict);
            if ($dump_status == false) {
                throw new \Exception('std_torrent_cannot_save');
            }

            app()->pdo->commit();
        } catch (\Exception $e) {
            // Delete the saved torrent file when torrent save success but still get Exception on other side
            if (isset($dump_status) && $dump_status == true) {
                unlink(Torrent::TorrentFileLoc($this->id));
            }

            app()->pdo->rollback();

            throw $e;
        }

       app()->site->writeLog("Torrent {$this->id} ({$this->title}) was uploaded by " . ($this->anonymous ? 'Anonymous' : app()->site->getCurUser()->getUsername()));
    }

    // Check and rewrite torrent flags based on site config and user's privilege of upload flags
    private function rewriteFlags()
    {
        foreach (['anonymous', 'hr'] as $flag) {
            $config = config('torrent_upload.enable_' . $flag);
            if ($config == 2) {  // if global config force enabled this flag
                $this->$flag = 1;
            } elseif ($config == 0) {                 // if global config disabled this flag
                $this->$flag = 0;
            } else {  // check if user can use this flag
                if (!app()->site->getCurUser()->isPrivilege('upload_flag_' . $flag)) {
                    $this->$flag = 0;
                }
            }
        }
    }

    // TODO update torrent status based on user class or their owned torrents count
    private function determineTorrentStatus() {
        $this->status = self::TORRENT_STATUS_CONFIRMED;
    }

    private function insertTags()
    {
        $tags = str_replace(',', ' ', $this->tags);
        $tags_list = explode(' ', $tags);

        // Get and cache the exist tags
        if (!app()->redis->exists('site:torrents_all_tags')) {
            $exist_tags = app()->pdo->createCommand('SELECT id,tag FROM tags')->queryAll();
            foreach ($exist_tags as $exist_tag) {
                app()->redis->zAdd('site:torrents_all_tags', $exist_tag['id'], $exist_tag['tag']);
            }
            app()->redis->expire('site:torrents_all_tags', 43200);
        }

        $tag_id_list = [];
        for ($i = 0; $i < min(count($tags_list), 10); $i++) {
            $tag = trim($tags_list[$i]);
            if (strlen($tag) > 0) {
                $tag_id = app()->redis->zScore('site:torrents_all_tags', $tag);  // check if it is exist tag in cache
                if ($tag_id == 0) {  // un-exist tag
                    if (config('torrent_upload.allow_new_custom_tags')) {
                        try {  // insert tag to database and cache
                            app()->pdo->createCommand('INSERT INTO `tags`(`tag`) VALUES (:tag)')->bindParams([
                                'tag' => $tag
                            ])->execute();
                            $tag_id = app()->pdo->getLastInsertId();
                            app()->redis->zAdd('site:torrents_all_tags', $tag_id, $tag);
                            $tag_id_list[] = ['tag_id' => $tag_id, 'torrent_id' => $this->id];
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                } else {
                    $tag_id_list[] = ['tag_id' => $tag_id, 'torrent_id' => $this->id];
                }
            }
        }

        // batchInsert into map
        if (count($tag_id_list) > 0) {
            app()->pdo->batchInsert('map_torrents_tags', $tag_id_list)->execute();
        }
    }

    // TODO sep to Traits
    private function setTorrentBuff($operator_id = 0, $beneficiary_id = 0, $buff_type = 'mod', $ratio_type = 'Normal', $upload_ratio = 1, $download_ratio = 1)
    {

    }

    // TODO it may take long time to get link details , so when torrent upload, we just push it to task worker
    private function getExternalLinkInfo()
    {
        if ($this->links)
            app()->redis->lPush('queue:external_link_via_torrent_upload', ['tid' => $this->id, 'links' => $this->links]);
    }

    private function setBuff()
    {
        $operator_id = 0;  // The buff operator id when torrent upload will be system
        // Add Large Buff and Random Buff
        if (config("buff.enable_large") && $this->file->size > config("buff.large_size")) {
            // TODO app()->pdo->createCommand();
        } elseif (config("buff.enable_random")) {
            // TODO app()->pdo->createCommand();
        }

        // TODO set uploader (or you can say torrents owner) buff

    }

    /**
     * the return array is like this when it's `single` torrent
     *
     * [
     *    "f1.text" => 1234
     * ]
     *
     * And will convert to `tree` like this when it's `multi` torrent by using the
     * private static function makeFileTree($array, $delimiter = '/')
     *
     * [
     *    "f1" => [
     *        "f2.text" => 1234,
     *        "f3.text" => 2234
     *     ]
     * ]
     *
     * Each result will be cached in redis since it will never change.
     *
     * @return bool|string
     */
    private function getFileTree()
    {
        $structure = array_column($this->torrent_list, 'size', 'filename');
        if ($this->torrent_type == self::TORRENT_TYPE_MULTI) {
            $structure = [$this->torrent_name => self::makeFileTree($structure)];
        }
        return json_encode($structure);
    }

    private static function makeFileTree($array, $delimiter = '/')
    {
        if (!is_array($array)) return array();

        $splitRE = '/' . preg_quote($delimiter, '/') . '/';
        $returnArr = array();
        foreach ($array as $key => $val) {
            // Get parent parts and the current leaf
            $parts = preg_split($splitRE, $key, -1, PREG_SPLIT_NO_EMPTY);
            $leafPart = array_pop($parts);

            // Build parent structure
            // Might be slow for really deep and large structures
            $parentArr = &$returnArr;
            foreach ($parts as $part) {
                if (!isset($parentArr[$part])) {
                    $parentArr[$part] = array();
                } elseif (!is_array($parentArr[$part])) {
                    $parentArr[$part] = array();
                }
                $parentArr = &$parentArr[$part];
            }

            // Add the final part to the structure
            if (empty($parentArr[$leafPart])) {
                $parentArr[$leafPart] = $val;
            }
        }
        return $returnArr;
    }

    /**
     * @param $dict
     * @param $key
     * @param null $type
     * @return mixed
     * @throws ParseErrorException
     */
    private function checkTorrentDict($dict, $key, $type = null)
    {
        if (!is_array($dict)) throw new ParseErrorException("std_not_a_dictionary");

        $value = $dict[$key];
        if (!isset($value)) throw new ParseErrorException("std_dictionary_is_missing_key");

        if (!is_null($type)) {
            $isFunction = 'is_' . $type;
            if (\function_exists($isFunction) && !$isFunction($value)) {
                throw new ParseErrorException("std_invalid_entry_in_dictionary");
            }
        }
        return $value;
    }
}