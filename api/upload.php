<?php
/**
 * Mahara: Electronic portfolio, weblog, resume builder and social networking
 * Copyright (C) 2006-2009 Catalyst IT Ltd and others; see:
 *                         http://wiki.mahara.org/Contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    mahara
 * @subpackage artefact-file
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 2006-2009 Catalyst IT Ltd http://catalyst.net.nz
 *
 */

define('INTERNAL', 1);
define('PUBLIC', 1);
define('SECTION_PLUGINTYPE', 'artefact');
define('SECTION_PLUGINNAME', 'blog');

$json = array();

require(dirname(dirname(dirname(__FILE__))) . '/init.php');
safe_require('artefact', 'file');
safe_require('artefact', 'blog');

if (!get_config('allowmobileuploads')) {
    jsonreply( array('fail' => 'Mobile uploads disabled') );
}

$token = '';
try {
    $token = param_variable('token');
    $token = trim($token);
}
catch (ParameterException $e) { }

if ($token == '') {
    jsonreply( array('fail' => 'Auth token cannot be blank') );
}

$username = '';
try {
    $username = trim(param_variable('username'));
}
catch (ParameterException $e) { }

if ($username == '') {
    jsonreply( array('fail' => 'Username cannot be blank') );
}

$data = new StdClass;
$USER = new User();

try {
    $USER->find_by_mobileuploadtoken($token, $username);
}
catch (AuthUnknownUserException $e) {
    jsonreply( array('fail' => 'Invalid user token') );
}

$data->owner = $USER->get('id'); // id of owner

$folder = '';
try {
    $folder = param_variable('foldername');
    $folder = trim($folder);

    if ($folder) {
        // TODO: create if doesn't exist - note assumes it is a base folder (hence null parent)
        $artefact = ArtefactTypeFolder::get_folder_by_name($folder, null, $data->owner);  // id of folder you're putting the file into
        if ($artefact) {
            $data->parent = $artefact->id;
            if ($data->parent == 0) {
                $data->parent = null;
            }
        }
        else {
            $fd = (object) array(
                'owner' => $data->owner,
                'title' => $folder,
                'parent' => null,
            );
            $f = new ArtefactTypeFolder(0, $fd);
            $f->commit();
            $data->parent = $f->get('id');
        }
    }
    else {
        $data->parent = null;
    }
}
catch (ParameterException $e) {
    $data->parent = null;
}

// Set title
$title = '';
try {
    $title = param_variable('title');
}
catch (ParameterException $e) { }

// Set description
$description = '';
try {
    $description = param_variable('description');
}
catch (ParameterException $e) { }

// Set tags
$tags = '';
try {
    $tags = explode(" ", param_variable('tags'));
}
catch (ParameterException $e) { }

$artefact_id = '';
if ( $_FILES ) {

    if ( ! $title ) { 
        basename($_FILES['userfile']['name']);
    }

    try {
        $data->title = ArtefactTypeFileBase::get_new_file_title($title, $data->parent, $data->owner);
        $data->description = $description;
        $data->tags = $tags;
        $artefact_id = ArtefactTypeFile::save_uploaded_file('userfile', $data);
        if ( $artefact_id ) {
            $json['id'] = $artefact_id;
        }
    }
    catch (QuotaExceededException $e) {
        jsonreply( array('fail' => 'Quota exceeded' ) );
    }
    catch (UploadException $e) {
        jsonreply( array('fail' => 'Failed to save file') );
    }
}


// Check for Journal ID to add a post to
$blog = ''; $blogpost = ''; $draft = 0; $allowcomments = 1;
try {
    $blog = param_integer('blog');
}
catch (ParameterException $e) { }

try {
    $blogpost = param_integer('blogpost');
}
catch (ParameterException $e) { }

try {
    $draft = param_variable('draft');
}
catch (ParameterException $e) { }

try {
    $allowcomments = param_variable('allowcomments');
}

catch (ParameterException $e) { }
// Check to see if we're creating a journal entry
if ( $blog && $title && $description ) {
    if (!get_record('artefact', 'id', $blog, 'owner', $USER->get('id'))) {
        // Blog security is also checked closer to when blogs are added, this 
        // check ensures that malicious users do not even see the screen for 
        // adding a post to a blog that is not theirs
        throw new AccessDeniedException(get_string('youarenottheownerofthisblog', 'artefact.blog'));
    }
    $postobj = new ArtefactTypeBlogPost($blogpost, null);
    $postobj->set('title', $title);
    $postobj->set('description', $description);
    $postobj->set('tags', $tags);
    $postobj->set('published', !$draft);
    $postobj->set('allowcomments', (int) $allowcomments);
    $postobj->set('parent', $blog);
    $postobj->set('owner', $USER->id);
    $postobj->commit();

    // If we created an artefact - attach it.
    if ( $artefact_id ) {
        $postobj->attach($artefact_id);
    }
}

// Here we need to create a new hash - update our own store of it and return it to the handset
jsonreply( array('success' => $USER->refresh_mobileuploadtoken($token) ) );

function jsonreply( $arr ) {
  global $json;
  if ( $json )
    $arr['sync'] = $json;
  header('Content-Type: application/json');
  echo json_encode($arr);
  exit;
}

