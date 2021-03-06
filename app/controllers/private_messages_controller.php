<?php
/**
 * Tatoeba Project, free collaborative creation of multilingual corpuses project
 * Copyright (C) 2009 DEPARIS Étienne <etienne.deparis@umaneti.net>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 *
 * @category PHP
 * @package  Tatoeba
 * @author   DEPARIS Étienne <etienne.deparis@umaneti.net>
 * @license  Affero General Public License
 * @link     http://tatoeba.org
 */


/**
 * Controller for private messages.
 *
 * @category API
 * @package  Controllers
 * @author   DEPARIS Étienne <etienne.deparis@umaneti.net>
 * @license  Affero General Public License
 * @link     http://tatoeba.org
 */
class PrivateMessagesController extends AppController
{
    public $name = 'PrivateMessages';

    public $helpers = array('Html', 'Date');


    /**
     * We don't use index at all : by default, we just display the
     * inbox folder to the user
     *
     * @return void
     */
    public function index()
    {
        $this->redirect(array('action' => 'folder', 'Inbox'));
    }

    /**
     * Function which will display the folders to the user.
     * The folder name is given in parameters, as messages are stored by
     * folder name in the database (SQL ENUM)
     *
     * @param string $folder The folder we want to display
     * @param string $status 'all', 'read', 'unread'.
     *
     * @return void
     */
    public function folder($folder = 'Inbox', $status = 'all')
    {
        $this->helpers[] = 'Pagination';
        $this->helpers[] = 'Messages';

        $folder = Sanitize::paranoid($folder);

        $currentUserId = $this->Auth->user('id');

        $conditions = array('folder' => $folder);
        if ($folder == 'Inbox') {
            $conditions['recpt'] = $currentUserId;
        } else if ($folder == 'Sent') {
            $conditions['sender'] = $currentUserId;
        } else if ($folder == 'Trash') {
            $conditions['user_id'] = $currentUserId;
        }

        if ($status == 'read') {
            $conditions['isnonread'] = 0;
        } else if ($status == 'unread') {
            $conditions['isnonread'] = 1;
        }

        $this->paginate = array(
            'PrivateMessage' => array(
                'conditions' => $conditions,
                'contain' => array(
                    'Sender' => array(
                        'fields' => array(
                            'id',
                            'username',
                            'image',
                        )
                    ),
                    'Recipient' => array(
                        'fields' => array(
                            'id',
                            'username',
                            'image',
                        )
                    )
                ),
                'order' => 'date DESC',
                'limit' => 20
            )
        );

        $content = $this->paginate();

        $this->set('folder', $folder);
        $this->set('content', $content);
    }

    /**
     * This function has to send the message, then to display the sent folder
     *
     * @return void
     */
    public function send()
    {
        if (!empty($this->data['PrivateMessage']['recpt'])
            && !empty($this->data['PrivateMessage']['content'])
        ) {
            $currentUserId = $this->Auth->user('id');

            //Remember new users are not allowed to send more than 5 messages per day
            $messagesTodayOfUser
                = $this->PrivateMessage->messagesTodayOfUser($currentUserId);
            if (CurrentUser::isNewUser() && $messagesTodayOfUser >= 5) {
                $this->Session->setFlash(
                    __(
                        "You have reached your message limit for today. ".
                        "Please wait until you can send more messages. ".
                        "If you have received this message in error, ".
                        "please contact administrators at ".
                        "team@tatoeba.org.",
                        true
                    )
                );
                $this->redirect(array('action' => 'folder', 'Sent'));
            }

            $this->data['PrivateMessage']['sender'] = $currentUserId;

            $recptArray = explode(',', $this->data['PrivateMessage']['recpt']);

            // loop to send msg to different dest.
            foreach ($recptArray as $recpt) {

                $recpt = trim($recpt);
                $recptId = $this->PrivateMessage->User->getIdFromUsername($recpt);

                // we send the msg only if the user exists.
                if ($recptId) {

                    $this->data['PrivateMessage']['recpt'] = $recptId;
                    $this->data['PrivateMessage']['user_id'] = $recptId;
                    $this->data['PrivateMessage']['folder'] = 'Inbox';
                    $this->data['PrivateMessage']['date']
                        = date("Y/m/d H:i:s", time());
                    $this->data['PrivateMessage']['isnonread'] = 1;
                    $this->PrivateMessage->save($this->data);
                    $this->PrivateMessage->id = null;

                    // we need to save the msg to our outbox folder of course.
                    $this->data['PrivateMessage']['user_id'] = $currentUserId;
                    $this->data['PrivateMessage']['folder'] = 'Sent';
                    $this->data['PrivateMessage']['isnonread'] = 0;
                    $this->PrivateMessage->save($this->data);
                    $this->PrivateMessage->id = null;
                } else {
                    $this->Session->setFlash(
                        format(
                            __(
                                'The user {username} to whom you want to send this message '.
                                'does not exist. Please try with another '.
                                'username.',
                                true
                            ),
                            array('username' => $recpt)
                        )
                    );
                    $this->redirect(array('action' => 'write'));
                }
            }
            $this->redirect(array('action' => 'folder', 'Sent'));
        } else {
            $this->Session->setFlash(
                __(
                    'You must fill at least the "To" field and the content field.',
                    true
                )
            );
            $this->redirect(array('action' => 'write'));
        }
    }

    /**
     * Function to show the content of a message
     *
     * @param int $messageId The identifiers of the message we want to read
     *
     * @return void
     */
    public function show($messageId)
    {
        $this->helpers[] = 'Messages';
        $this->helpers[] = 'PrivateMessages';

        $messageId = Sanitize::paranoid($messageId);
        $pm = $this->PrivateMessage->getMessageWithId($messageId);

        // Redirection to Inbox if the user tries to view a messages that
        // is not theirs.
        $recipientId = $pm['PrivateMessage']['recpt'];
        $senderId = $pm['PrivateMessage']['sender'];
        $currentUserId = CurrentUser::get('id');

        if ($recipientId != $currentUserId && $senderId != $currentUserId) {
            $this->redirect(
                array(
                    'action' => 'folder',
                    'Inbox'
                )
            );
        }

        // Setting message as read
        if ($pm['PrivateMessage']['isnonread'] == 1) {
            $pm['PrivateMessage']['isnonread'] = 0;
            $this->PrivateMessage->save($pm);
        }


        $folder =  $pm['PrivateMessage']['folder'];
        $title = $pm['PrivateMessage']['title'];
        $message = $this->_getMessageFromPm($pm['PrivateMessage']);
        $author = $pm['Sender'];
        $messageMenu = $this->_getMenu($folder, $messageId);

        $this->set('messageMenu', $messageMenu);
        $this->set('title', $title);
        $this->set('author', $author);
        $this->set('message', $message);
        $this->set('folder', $folder);
    }


    /**
     *
     */
    private function _getMessageFromPm($privateMessage)
    {
        $message['created'] = $privateMessage['date'];
        $message['text'] = $privateMessage['content'];

        return $message;
    }


    /**
     *
     *
     */
    private function _getMenu($folder, $messageId)
    {
        $menu = array();

        if ($folder == 'Trash') {
            $menu[] = array(
                'text' => __('restore', true),
                'url' => array(
                    'action' => 'restore',
                    $messageId
                )
            );
        } else {
            $menu[] = array(
                'text' => __('delete', true), 
                'url' => array(
                    'action' => 'delete', 
                    $folder, 
                    $messageId
                )
            );
        }
        
        if ($folder == 'Inbox') {
            $menu[] = array(
                'text' => __('mark as unread', true), 
                'url' => array(
                    'action' => 'mark',
                    'Inbox',
                    $messageId
                )
            );
                        
            $menu[] = array(
                'text' => __('reply', true), 
                'url' => '#reply'
            );
        }

        return $menu;
    }

    /**
     * Delete message function
     *
     * @param string $folderId  The folder identifier where we are while
     * deleting this message
     * @param int    $messageId The identifier of the message we want to delete
     *
     * @return void
     */
    public function delete($folderId, $messageId)
    {
        $messageId = Sanitize::paranoid($messageId);
        $message = $this->PrivateMessage->findById($messageId);

        if ($message['PrivateMessage']['user_id'] == CurrentUser::get('id')) {
            $message['PrivateMessage']['folder'] = 'Trash';
            $this->PrivateMessage->save($message);
        }

        $this->redirect(array('action' => 'folder', $folderId));
    }

    /**
     * Restore message function
     *
     * @param int $messageId The identifier of the message we want to restore
     *
     * @return void
     */
    public function restore($messageId)
    {

        $messageId = Sanitize::paranoid($messageId);

        $message = $this->PrivateMessage->findById($messageId);

        if ($message['PrivateMessage']['recpt'] == $this->Auth->user('id')) {
            $folder = 'Inbox';
        } else {
            $folder = 'Sent';
        }

        $message['PrivateMessage']['folder'] = $folder;
        $this->PrivateMessage->save($message);
        $this->redirect(array('action' => 'folder', $folder));
    }

    /**
     * Generalistic read/unread marker function.
     *
     * @param string $folderId  The folder identifier where we are while
     * marking this message
     * @param int    $messageId The identifier of the message we want to mark
     *
     * @return void
     */
    public function mark($folderId, $messageId)
    {
        $messageId = Sanitize:: paranoid($messageId);

        $message = $this->PrivateMessage->findById($messageId);
        switch ($message['PrivateMessage']['isnonread']) {
            case 1 : $message['PrivateMessage']['isnonread'] = 0;
                break;
            case 0 : $message['PrivateMessage']['isnonread'] = 1;
                break;
        }
        $this->PrivateMessage->save($message);
        $this->redirect(array('action' => 'folder', $folderId));
    }

    /**
     * Create a new message
     *
     * @param string $recipients The login, or the string containing various login
     *                           separated by a comma, to which we have to send the
     *                           message.
     *
     * @return void
     */
    public function write($recipients = null)
    {
        $this->helpers[] = "PrivateMessages";
        
        $userId = CurrentUser::get('id');
        $isNewUser = CurrentUser::isNewUser();

        //For new users, check how many messages they have sent in the last 24hrs
        $canSend = true;
        $messagesToday = 0;
        if ($isNewUser) {
            $messagesToday = $this->PrivateMessage->messagesTodayOfUser($userId);
            $canSend = $messagesToday < 5;
        }
        $this->set('messagesToday', $messagesToday);
        $this->set('canSend', $canSend);
        $this->set('isNewUser', $isNewUser);

        if ($recipients == null) {
            $recipients = '';
        }

        $this->set('recipients', $recipients);
    }

    /**
     * function called to add a list to a pm
     *
     * @param string $type         The type of object to join to the message
     * @param int    $joinObjectId The identifier of the object to join
     *
     * @return void
     */
    public function join($type = null, $joinObjectId = null)
    {
        $type = Sanitize::paranoid($type);
        $joinObjectId = Sanitize::paranoid($joinObjectId);

        if ($type != null && $joinObjectId != null) {
            $this->params['action'] = 'write';
            $this->set('msgPreContent', '['.$type.':'.$joinObjectId.']');
            $this->write();
        } else {
            $this->redirect(array('action' => 'write'));
        }
    }
}
?>
