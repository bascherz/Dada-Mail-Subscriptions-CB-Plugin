<?php
/**
    Name:    CB Dada Mail Subscriptions
    Version: 3.9, native for Joomla 3.x
    Date:    April 2020
    Author:  Bruce Scherzinger
    Email:   joomlander@scherzinger.org
    URL:     http://joomla.org
    Purpose: Community Builder tab to allow subscription control of Dada Mail lists in member profiles.

    License: GNU/GPL
    This is free software. This version may have been modified pursuant
    to the GNU General Public License, and as distributed it includes or
    is derivative of works licensed under the GNU General Public License or
    other free or open source software licenses.
    (C) 2007-2017 Bruce Scherzinger

    Dada Mail is Free Software and is released under the Gnu Public License.
    http://dadamailproject.com/
*/
defined('_JEXEC') or die('Direct Access to this location is not allowed.');

// Language-specific strings
// Error pop-ups
define("CHANGES_NOT_SAVED","CHANGES NOT SAVED! If you receive an email to the contrary, don't believe it.");
define("ADDRESS_ALREADY_IN_USE","One or more email addresses entered already in use by another member!");
define("DUPLICATE_ADDRESS_ENTERED","Email address cannot be used more than once per member!");

// Default messages (if not specified in back end)
define("DEFAULT_UNSUBSCRIBE_MSG","successfully unsubscribed [EMAIL] from [LIST] email list at [SITE].");
define("DEFAULT_SUBSCRIBE_MSG","successfully subscribed [EMAIL] to [LIST] email list at [SITE].");
define("DEFAULT_AUTOSUBSCRIBE_MSG","[EMAIL] automatically subscribed to [LIST] email list at [SITE].");
define("DEFAULT_ADDRESS_CHANGE_MSG","successfully changed [OLD] to [EMAIL].");
define("DEFAULT_EMAIL_SUBJECT","[SITE] Email List Subscription Update");
define("ALL_EMAIL_LISTS","all email lists");

// These registrations handle administrator modifications to user settings.
$_PLUGINS->registerFunction('onUserActive',                 'afterUserActivated',   'getDadaMailTab');
$_PLUGINS->registerFunction('onBeforeUserUpdate',           'beforeUserUpdate',     'getDadaMailTab');
$_PLUGINS->registerFunction('onBeforeUpdateUser',           'beforeUserUpdate',     'getDadaMailTab');
$_PLUGINS->registerFunction('onAfterUserUpdate',            'afterUserUpdate',      'getDadaMailTab');
$_PLUGINS->registerFunction('onAfterUpdateUser',            'afterUserUpdate',      'getDadaMailTab');
$_PLUGINS->registerFunction('onBeforeDeleteUser',           'beforeDeleteUser',     'getDadaMailTab');
$_PLUGINS->registerFunction('onAfterLogin',                 'afterLogin',           'getDadaMailTab');
$_PLUGINS->registerFunction('onBeforeUserProfileDisplay',   'beforeProfileDisplay', 'getDadaMailTab');

class getDadaMailTab extends cbTabHandler {

    function __construct()
    {
        $this->cbTabHandler();
    }

    /******* EVENT HANDLERS *******/

    /*
     * Fetches the states of the actual settings in the Dada Subscriptions table.
     * Overrides what may currently be set in #__comprofiler. This avoids having
     * to initially populate the CB fields associated with the email lists.
     * View profile events - ui=1 front, ui=2 backend
     */
    function refreshProfileSubscriptions($user)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();

        $database->setQuery("SELECT * FROM dada_settings WHERE setting='list_name'");
        $lists = $database->loadObjectList();

        // Get all email addresses for this user's account
        $addresses = $this->getEmails($user, $user_emails, $garbage);

        // Fetch list of subscriptions from Dada subscribers table.
        $database->setQuery("SELECT * FROM dada_subscribers WHERE email IN ($user_emails)");
        $subscriptions = $database->loadObjectList();

        // Point to plugin parameters
        $params = $this->params;

        // Assess which lists the user is subscribed to.
        if ($lists)
        {
            foreach ($lists as $list)
            {
                // If an options field name is specified, use that as the source for options for all email lists.
                // Otherwise, get the list email field name.
                // In either case, find the associated option list.
                $dada_lists_field = $params->get('dada_email_opts',"");
                if ($dada_lists_field == "")
                    $optionsfield = "cb_".$list->list;
                else
                    $optionsfield = $dada_lists_field;
                $listfield = "cb_".$list->list;

                // Set the CB field value for each list based on the actual subscription.
                $database->setQuery("SELECT * FROM #__comprofiler_fields WHERE name='$optionsfield'");
                $field = $database->loadObject();
                if ($field->type == 'checkbox')
                {
                    $subscribed = 0;
                    foreach ($subscriptions as $subscription)
                    {   
                        if ($subscription->list == $list->list)
                        {
                            $subscribed = 1;
                            continue;
                        }
                    }
                    // Build and do the update query
                    $querytext = "UPDATE #__comprofiler SET $listfield = '$selections' WHERE user_id=$user->id";
                    $database->setQuery($querytext);
                    $database->execute();
                }
                elseif ($field->type == 'multicheckbox' || $field->type == 'codemulticheckbox' || $field->type == 'querymulticheckbox' ||
                        $field->type == 'multiselect'   || $field->type == 'codemultiselect'   || $field->type == 'querymultiselect')
                {
                    /*
                     * Whatever the user had chosen before will be overridden by what the
                     * dada_subscriptions table says and how that maps to the addresses
                     * entered by this user and supported by the site.
                     */
                    $selections = "";
                    foreach ($subscriptions as $subscription)
                    {
                        if ($subscription->list == $list->list)
                        {
                            foreach ($addresses as $address)
                            {
                                if (strtolower($subscription->email) == strtolower($address->value))
                                {
                                    // Get the title of the option associated with this email address
                                    $database->setQuery("SELECT fieldtitle FROM #__comprofiler_field_values WHERE fieldid=".$field->fieldid." AND ordering=".$address->index);
                                    $title = $database->loadResult();

                                    // See if checkbox is checked
                                    if(stripos($selections,$title) === false)
                                    {
                                        if (strlen($selections) > 0) $selections .= "|*|";
                                        $selections .= $title;
                                    }
                                }
                            }
                        }
                    }
                    // Set the field to update.
                    $query = $database->getQuery(true);

                    // Build and do the update query
                    $querytext = "UPDATE #__comprofiler SET $listfield = '$selections' WHERE user_id=$user->id";
                    $database->setQuery($querytext);
                    $database->execute();
                }
            }
        }
    }
    
    function afterLogin($user, $garbage)
    {
        // Since the onBeforeUserProfileEditDisplay event doesn't update the display AFTER handling user events (it does so BEFORE),
        // update the profile fields at login so that by the time the user edits the profile they are set.
        $result = $this->refreshProfileSubscriptions($user);
    }
    function beforeProfileDisplay(&$user, $nada, $cbUserIsModerator, $cbMyIsModerator)
    {
        // Refresh the profile just before displaying it.
        $result = $this->refreshProfileSubscriptions($user);
    }

    /*
     * This function mainly just checks to see if the user changed his/her email address(es) or profile fields.
     * If so, all existing subscription records matching the old email address are modified to have the new emai
     * address and the profile field values are replaced even if now null. This function does not subscribe any
     * new addresses to any Dada lists (see afterUserUpdate).
     */
    function beforeUserUpdate(&$user, &$cbUser)
    {
        // Check for duplicate email addresses
        if ($this->anyDuplicateAddresses($user, $cbUser)) return false;

        // Let's get the database
        static $database;
        $database = JFactory::getDBO();
   
        // Point to plugin parameters
        $params = $this->params;

        // Fetch list of lists from Dada settings table.
        $database->setQuery("SELECT list FROM dada_settings WHERE setting='list'");
        $lists = $database->loadObjectList();

        // Get all email addresses for this user's account
        $addresses = $this->getEmails($user, $user_emails, $fieldsquery);

        // Prepare to notify the user
        $message = "";
        $number = 0;

        // If any of the user's email addresses changed, modify all matching subscription records.
        foreach ($addresses as $address)
        {
            // The field providing the email address is provided by the getEmails method.
            $field = $address->field;
            if ($address->value)
                $old_email = $address->value;
            else
                $old_email = "{nothing}";

            /*
             * No attribute identifying the table from which the email address came is provided
             * by getEmails. So we have to do a little guess work here.
             */
            if ($cbUser->$field)
                $new_email = trim($cbUser->$field);
            else
                $new_email = trim($user->$field);
            if (!$new_email) $new_email = "{nothing}";

            /*
             * What we're checking for here is really a change in email address, not a change in
             * subscribership. Of course, if an email address that is subscribed to a Dada list is
             * modified, it will be modified for every list to which it was subscribed.
             */
            if (strtolower($old_email) != strtolower($new_email))
            {
                // Loop through all the lists. Need to do this to check for existing duplicates.
                foreach ($lists as $list)
                {
                    // See if there is already a record like the one we are about to create. 
                    $database->setQuery("SELECT * FROM dada_subscribers WHERE email='".$new_email."' AND list='".$list->list."'");
                    if ($database->loadObject() || $new_email == "{nothing}")
                    {
                        // There already is a record with this address for this list, so just delete the record with the old email address.
                        $database->setQuery("DELETE FROM dada_subscribers WHERE email='".$old_email."' AND list='".$list->list."'");
                    }
                    else
                    {
                        // No record for the old address, so update the email address in the existing record (if any)
                        $database->setQuery("UPDATE dada_subscribers SET email='$new_email' WHERE email='$old_email' AND list='".$list->list."'");
                    }
                    $database->execute();
                }
                $number++;
                $message .= str_replace(array('[LIST]',    '[OLD]',   '[EMAIL]', '[LABEL]',      '[FIELD]'),
                                        array($list->value,$old_email,$new_email,$address->label,$address->field),
                                        $params->get('changed_email_msg',DEFAULT_ADDRESS_CHANGE_MSG));
            }
        }
        // Send notification of email address changes, if any.
        if ($number > 0)
        {
            $result = $this->NotifyUser($user, $message);
        }
        return true;
    }

    /*
     * Checks all profile list subscription selections and compares them against
     * existing subscriptions.
     */
    function afterUserUpdate ($user, $cbUser, $something=true)
    {
        // Initialize email message
        $message = "";
        
        // Update the subscriptions for this user
        $number = $this->UpdateSubscriptions($user, $message);

        // If applicable, notify the user of the subscription changes.
        if ($number) $result = $this->NotifyUser($user, $message);

        return true;
    }

    /*
     * REGISTRATION SEQUENCE:
     *  1. User registers - If neither confirmation nor approval is required, user can be processed.
     *  2. If confirmation is required, this happens next. - If approval is not required, user can be processed.
     *  3. If admin approval is required, this happens next. - If confirmation is not required, user can be processed.
     */

    /*
     * Handles user registration email address entries, mainly checking for duplicates.
     */
    function beforeUserRegisters ($user, $cbUser, $something=false)
    {
        // Check for duplicate email addresses
        if ($this->anyDuplicateAddresses($user, $cbUser)) return false;
        return true;
    }

    /*
     * Handles user activation, which is the event that completes the user object store after a new user is approved.
     */
    function afterUserActivated (&$user, $ui, $cause, $mailToAdmins, $mailToUser)
    {
        $result = $this->afterNewUser($user, $user);
        return true;
    }
    
    /*
     * Subscribes the new user to all email lists.
     */
    function afterNewUser($user, $cbUser, $stored = false, $something = true)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();
   
        // Point to plugin parameters
        $params = $this->params;

        // Get lists to auto-subscribe and notification email format
        $auto = str_replace(" ","",$params->get('dada_autosubscribe','')).",";  // remove spaces from auto list
        $format = intval($params->get('email_format','0'));

        // Fetch list of lists from Dada settings table.
        $database->setQuery("SELECT * FROM dada_settings WHERE setting='list_name'");
        $lists = $database->loadObjectList();
        
        // Initialize email message
        $message = "";
        
        // Subscribe new user's account email to all auto-subscribe lists.
        $number = 0;
        foreach ($lists as $list)
        {
            if ($auto == "*," || strstr($auto,$list->list.","))
            {
                /* Insert the address into the dada_subscriptions table */
                $result = $this->SubscribeAddress($user->email, $list->list);
                $message .= str_replace(array('[LIST]','[EMAIL]'),array($list->value,$user->email),$params->get('autosubscribe_email_msg',DEFAULT_AUTOSUBSCRIBE_MSG));
                $number++;
            }
        }
        // Based on the auto-subscriptions, update the profile fields.
        $result = $this->refreshProfileSubscriptions($user);

        // If applicable, notify the user of the subscription changes.
        if ($number) $this->NotifyUser($user, $message);

        return true;
    }

    /*
     * Remove all records from the Dada Mail subscriptions table that contain
     * any email address belonging to this user.
     */
    function beforeDeleteUser($user, $store = true)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();
   
        // Point to plugin parameters
        $params = $this->params;

        // See if we should even be doing this
        if ($params->get('send_termination_notice',"No") == "No") return false;
        
        // Get a complete list of all extended email addresses
        $addresslist = $fieldsquery = "";
        $addresses = $this->getEmails($user, $addresslist, $fieldsquery);

        // Unsubscribe all of this user's extended email addresses from all lists.
        foreach ($addresses as $address)
        {
            if ($address->value)
            {
                // Unsubscribe this address from all lists
                $database->setQuery("DELETE FROM dada_subscribers WHERE email='".$address->value."'");
                $database->execute();
                $message .= str_replace(array('[LIST]','[EMAIL]'),array(ALL_EMAIL_LISTS,$address->value),$params->get('unsubscribe_email_msg',DEFAULT_UNSUBSCRIBE_MSG));
            }
        }

        // If user is approved, notify that all addresses were unsubscribed from all lists
        if ($user->approved == 1)
            if ($message) $this->NotifyUser($user, $message);
        
	return true;
    }

    /******* UTILITY METHODS *******/
    /*
     * Checks for duplicate email addresses entered by the current user, both in the
     * entry form and also in the database for all addresses in $addresses. Raises an
     * error and returns true if any dupes found, false otherwise (no error raised).
     * Note that if any duplicates are found at this point, all user edits just made
     * are discarded. To be effective, must be called prior to committing entries to
     * the database (i.e., from the "onBefore" handlers).
     */
    function anyDuplicateAddresses($user, $cbUser)
    {
        // Let's get the database
        static $database;
        global $_PLUGINS;
        $database = JFactory::getDBO();

        // Point to plugin parameters
        $params = $this->params;

        // Get a list of CB fields to fetch email addresses from, if any
        $dada_emails = intval($params->get('dada_emails',"0"));
        $user_emails = "'EMAILADDRESS'";
        $addresses = array();
        for ($email = 1; $email <= $dada_emails; $email++)
        {
            $field = $params->get("dada_email$email","");
            if (strlen(trim($field)) > 0)
            {
                $addresses[$email]->field = $field;
                if ($cbUser->$field)
                    $fieldvalue = $cbUser->$field;
                else
                    $fieldvalue = $user->$field;
                if (strlen(trim($fieldvalue)) > 0)
                {
                    $addresses[$email]->value = $fieldvalue;
                    $user_emails .= ",'".$fieldvalue."'";
                }
            }
        }

        // Ensure all email addresses are unique and unique to this user
        $in_other_emails = "";
        foreach ($addresses as $address)
        {
            if ($address->value)
            {
                // Error if any email address was entered more than once
                if (substr_count(strtolower($user_emails),"'".strtolower($address->value)."'") > 1)
                {
                    $_PLUGINS->raiseError(0);
                    $_PLUGINS->_setErrorMSG(DUPLICATE_ADDRESS_ENTERED.' ('.$address->value.')<br>'.CHANGES_NOT_SAVED);
                    return true;
                }
                elseif ($address->field) $in_other_emails .= " OR (LOWER(".$address->field.") IN (".strtolower($user_emails)."))";
            }
        }
        $query = "SELECT * FROM #__users AS u".
                 " INNER JOIN #__comprofiler AS c ON u.id=c.id".
                 " WHERE (u.id <> ".$user->id.")".
                 " AND ( (LOWER(email) IN (".strtolower($user_emails).")) $in_other_emails )";
        $database->setQuery($query);

        // If any records are returned from the above query, at least one email address is not unique
        $duperecords = $database->loadObjectList();
        if (count($duperecords))
        {
            $_PLUGINS->raiseError(0);
            $_PLUGINS->_setErrorMSG(ADDRESS_ALREADY_IN_USE.' ('.$address->value.')<br>'.CHANGES_NOT_SAVED);
            return true;
        }
        // no dupes found
        return false;
    }

    /*
     * Subscribes an address to the list. This includes adding a row to the dada_subscribers table
     * but NOT setting the corresponding profile flag in #__comprofiler.
     */
    function SubscribeAddress($email, $list)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();

        $added = false;
        $database->setQuery("SELECT * FROM dada_subscribers WHERE email='$email' AND list='$list'");
        if (!$database->loadObject())
        {
            // Subscribe the address to the list.
            $database->setQuery("INSERT INTO dada_subscribers SET email='$email',list='$list',list_type='list',list_status=1;");
            $database->execute();
            $added = true;
        }
        return $added;
    }
    
    /*
     * Unsubscribes an address from the list. This includes deleting a row from the dada_subscribers table
     * (if any) but NOT clearing the corresponding profile flag in #__comprofiler.
     */
    function unSubscribeAddress($email, $list)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();
   
        // Unsubscribe the user from the list.
        $database->setQuery("DELETE FROM dada_subscribers WHERE email='$email' AND list='$list'");
        $database->execute();
        return true;
    }

    /*
     * Fetches all email addresses associated with the current user and returns them in an array.
     * Also returns a database query fragment for selecting email addresses from the subscription table.
     * Use of $fieldsquery requires a JOIN query between #__users and #__comprofiler based on id.
     */
    function getEmails($user, &$addresslist, &$fieldsquery)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();

        // Point to plugin parameters
        $params = $this->params;

        // Setup some default lists and strings
        $emails[] = array();
        $addresslist = "'EMAILADDRESS'"; 
        $fieldsquery = "";
        
        // Get this user's entire record
        $database->setQuery("SELECT * FROM #__users as u INNER JOIN #__comprofiler as c ON u.id=c.id WHERE u.id=".$user->id);
        $row = $database->loadObject();

        // Get a list of CB fields to fetch email addresses from, if any
        $dada_emails = intval($params->get('dada_emails',"0"));
        if ($dada_emails)
        {
            for ($email = 1; $email <= $dada_emails; $email++)
            {
                $field = $params->get('dada_email'.$email,"");
                if (strlen(trim($field)) > 0)
                {
                    $database->setQuery("SELECT * FROM #__comprofiler_fields WHERE name='$field'");
                    $fieldinfo = $database->loadObject();
                    $emails[$email] = new stdClass();
                    $emails[$email]->label = $fieldinfo->title;
                    $emails[$email]->field = $field;
                    $emails[$email]->value = $row->$field;
                    $emails[$email]->index = $email;
                    if (strlen($fieldsquery)) $fieldsquery .= ","; $fieldsquery .= "$field";
                    if (strlen($addresslist)) $addresslist .= ","; $addresslist .= "'".$row->$field."'";
                }
            }
        }
        else
        {
            // Only using the primary email address
            $database->setQuery("SELECT * FROM #__comprofiler_fields WHERE name='email'");
            $fieldinfo = $database->loadObject();
            $emails[$email] = new stdClass();
            $emails[1]->label = $fieldinfo->title;
            $emails[1]->field = $fieldsquery = 'email';
            $emails[1]->value = $user->email;
            $emails[1]->index = 1;
            $addresslist = "'".$user->email."'";
        }
        return array_reverse($emails,true);
    }

    /*
     * Separates all profile field:pairs associated with each email address and returns them in an array
     * indexed in the same order getEmails orders the email addresses.
     */
    function getProfileFields($user, &$addresslist, &$fieldsquery)
    {
        // Point to plugin parameters
        $params = $this->params;

        // Setup some default lists and strings
        $fieldpairs[] = array();

        // Get a list of CB fields to fetch email addresses from, if any
        $dada_emails = intval($params->get('dada_emails',"0"));
        if ($dada_emails)
        {
            for ($email = 1; $email <= $dada_emails; $email++)
            {
                $profilefields = $params->get('dada_email'.$email."fields","");
                if (strlen(trim($profilefields)) > 0)
                {
                    // Separate list of field:pairs into a double-subscripted array
                    $thispair = explode(",",preg_replace("/\s+/","",$profilefields));
                    $fieldpairs[$email] = new stdClass();
                    $fieldpairs[$email]->firstname = $thispair[0];
                    $fieldpairs[$email]->lastname = $thispair[1];
                }
            }
        }
        else
        {
            // Only using the primary email address
            $fieldpairs[$email] = new stdClass();
            $fieldpairs[1]->firstname = "firstname";
            $fieldpairs[1]->lastname = "lastname";
        }
        return array_reverse($fieldpairs,true);
    }

    /*
     * The specified list must have a multicheckbox field associated with it.
     * Returns the names of the fields associated with the specified list in an array.
     * The array key is the field label, which makes it easy to lookup by exploding the
     * list multicheckbox. All options are returned and if the specified user is subscribed
     * to one, the subscribed attribute is set to TRUE.
     */
    function getOptionList($list,$user)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();
    
        // Get a list of CB fields to fetch email addresses from, if any
        $params = $this->params;
        $dada_emails = intval($params->get('dada_emails',"0"));
        $emails[] = array();
        for ($email = 1; $email <= $dada_emails; $email++)
        {
            $field = $params->get("dada_email".$email,"");
            if (strlen(trim($field)) > 0)
            {
                $emails[$email] = new stdClass();
                $emails[$email] = $field;
            }
        }

        // If an options field name is specified, use that as the source for options for all email lists.
        // Otherwise, get the list email field name.
        // In either case, find the associated option list.
        $dada_lists_field = $params->get('dada_email_opts',"");
        if ($dada_lists_field == "")
            $opt_field_name = "cb_".$list->list;
        else
            $opt_field_name = $dada_lists_field;

        /* Get a complete list of options for this list. Note that we don't really need
         * most of the stuff this query fetches multiple times, but doing it this way
         * requires only a single query, which is always nice.
         */ 
        $database->setQuery("SELECT v.* FROM #__comprofiler_field_values as v".
                            " INNER JOIN #__comprofiler_fields as f".
                            " ON v.fieldid=f.fieldid".
                            " WHERE f.name='".$opt_field_name."'".
                            " ORDER BY v.ordering");
        $options = $database->loadObjectList();

        // Get the necessary information for this user
        $database->setQuery("SELECT * FROM #__users as u INNER JOIN #__comprofiler as c ON u.id=c.id WHERE u.id=".$user->id);
        $userstuff = $database->loadObject();

        /* Attributes in returned array are:
         *  array key - field title (label on the field...CB uses this to denote selection)
         *  field - name of database field
         *  email - email address user entered into this field (if any)
         *  ordering - order of field as defined in option list
         *  selected - true if user selected to subscribe this email address to the list
         */
        foreach ($options as $option)
        {
            $field = $emails[$option->ordering];
            $address[$option->fieldtitle] = new stdClass();
            $address[$option->fieldtitle]->field = $field;
            $address[$option->fieldtitle]->email = $userstuff->$field;
            $address[$option->fieldtitle]->ordering = $option->ordering;
            $address[$option->fieldtitle]->selected = FALSE;
        }
        // Get the selected options for the specified list for this user
        $field = "cb_".$list->list;
        $selections = $userstuff->$field;

        // Separate selected options.
        if ($selections)
        {
            $selections = explode('|*|',$selections);
            foreach ($selections as $selection)
            {
                $address[$selection]->selected = true;
            }
        }
        return $address;
    }

    /*
     * Based on the choices the user or administrator made for Dada list subscriptions, update
     * the Dada subscriptions table. Also, build a message to send to the user/admin as a
     * notification. If any changes in subscriptions occur during this method, a non-zero number
     * is returned and message will be non-zero length.
     */
    function UpdateSubscriptions ($user, &$message)
    {
        // Let's get the database
        static $database;
        $database = JFactory::getDBO();

        // Get back-end table names
        $params = $this->params;
        
        // Get email notice format
        $format = intval($params->get('email_format','0'));
        
        // Fetch list of lists from Dada settings table.
        $database->setQuery("SELECT * FROM dada_settings WHERE setting='list_name'");
        $lists = $database->loadObjectList();
        
        // Get all email addresses for this user's account
        $addresses = $this->getEmails($user, $user_emails, $fieldsquery);

        // Assess which lists the user wishes to be un/subscribed from/to.
        $message = "";
        $number = 0;
        foreach ($lists as $list)
        {
            // Fetch list of subscriptions from Dada subscribers table.
            $database->setQuery("SELECT * FROM dada_subscribers WHERE email IN ($user_emails) AND list='".$list->list."'");
            $subscriptions = $database->loadObjectList();

            // Set the CB field value for each list based on the actual subscription.
            $listfield = "cb_".$list->list;
            $database->setQuery("SELECT * FROM #__comprofiler_fields WHERE name='$listfield'");
            $field = $database->loadObject();
            if($field->type == 'checkbox')
            {
                // Assume not subscribed
                $subscribed = false;
                foreach ($subscriptions as $subscription)
                {
                    if ($subscription->list == $list->list)
                    {
                        // A subscription to this list already exists
                        $subscribed = true;
                        continue;
                    }
                }
                // Unsubscribe if subscribed and not checked, and vice versa.
                $database->setQuery("SELECT cb_".$list->list." FROM #__comprofiler WHERE id=".$user->id);
                $boxchecked = $database->loadResult();
            
                if ($subscribed && !$boxchecked)
                {
                    $result = $this->unSubscribeAddress($user->email, $list->list);
                    $message .= $params->get('unsubscribe_email_msg',DEFAULT_UNSUBSCRIBE_MSG);
                    $number++;
                }
                elseif ($boxchecked && !$subscribed)
                {
                    if ($this->SubscribeAddress($user->email, $list->list))
                    {
                        $message .= $params->get('subscribe_email_msg',DEFAULT_SUBSCRIBE_MSG);
                        $number++;
                    }
                }
                // Replace notice message placeholders
                $message = str_replace(array('[LIST]','[EMAIL]'),array($list->value,$user->email),$message);
            }
            elseif ($field->type == 'multicheckbox' || $field->type == 'codemulticheckbox' || $field->type == 'querymulticheckbox' ||
                    $field->type == 'multiselect'   || $field->type == 'codemultiselect'   || $field->type == 'querymultiselect')
            {
                // Get a list of which address(es) this user has subscribed to this list
                $addresses = $this->getOptionList($list, $user);

                // Check each address to see if it is subscribed
                foreach ($addresses as $address)
                {
                    // No sense checking for a subscription if there's no email adddress defined
                    if (strlen(trim($address->email)) > 0)
                    {
                        // Assume not subscribed
                        $subscribed = false;
                        foreach ($subscriptions as $subscription)
                        {
                            if ($subscription->email == $address->email)
                            {
                                // A subscription to this list already exists
                                $subscribed = true;
                                continue;
                            }
                        }
                        // Unsubscribe if subscribed and not checked, and vice versa.
                        if ($subscribed && !$address->selected)
                        {
                            $result = $this->unSubscribeAddress($address->email, $list->list);
                            $message .= $params->get('unsubscribe_email_msg',DEFAULT_UNSUBSCRIBE_MSG);
                            $number++;
                        }
                        elseif (!$subscribed && $address->selected && trim($address->email) != '')
                        {
                            if ($this->SubscribeAddress($address->email, $list->list))
                            {
                                $message .= $params->get('subscribe_email_msg',DEFAULT_SUBSCRIBE_MSG);
                                $number++;
                            }
                        }
                        // Replace notice message placeholders. We have to replace [EMAIL] now
                        // since the extended addresses are not handled below.
                        $message = str_replace(array('[LIST]','[EMAIL]'),array($list->value,$address->email),$message);
                    }
                }
            }
        }
        return $number;
    }
    
    /*
     * Notify the user of any subscription changes. Note: this method should not be called
     * unless there is substance to the message.
     */
    function NotifyUser($user, $message, $subject='')
    {
        // Create application object
        $mainframe = JFactory::getApplication();

        // Let's get the database
        static $database;
        $database = JFactory::getDBO();
   
        // Setup a mailer
        $mailer = JFactory::getMailer();
 
        // If applicable, notify the user of the subscription changes.
        $params = $this->params;
        $notify = $params->get('send_email_notice','No');
        if ($notify != "No")
        {
            // Get message prefix and suffix
            $prefix = $params->get('email_prefix','');
            $suffix = $params->get('email_suffix','');
        
            // Get email addresses
            $from_name  = $params->get('email_from_name',$mainframe->getCfg('fromname'));
            $from_addr  = $params->get('email_from_addr',$mainframe->getCfg('mailfrom'));
            $admin_addr = $params->get('admin_addr'     ,$mainframe->getCfg('mailfrom'));

            // Replace notice message placeholders
            if (strlen($subject) == 0)
                $subject = $params->get('email_subject',DEFAULT_EMAIL_SUBJECT);
            $subject = str_replace(array('[SITE]','[EMAIL]','[USER]'),array($mainframe->getCfg('sitename'),$user->email,$user->name),$subject);
            $message = str_replace(array('[SITE]','[EMAIL]','[USER]'),array($mainframe->getCfg('sitename'),$user->email,$user->name),$prefix.$message.$suffix);

            // Get email format
            $format = intval($params->get('email_format','0'));

            // Who gets this notification?
            $recipient = array();
            $copyto = array();
            $bcc = array();
            switch ($notify)
            {
                case "Admin": // admin only
                    $recipient = preg_split("/[\s,]+/",$admin_addr);
                    break;
                case "User": // user only
                    $recipient = $user->email;
                    break;
                case "Both": // user and admin
                default:
                    $recipient = $user->email;
                    $bcc = preg_split("/[\s,]+/",$admin_addr);
                break;
            }
            
            // Build e-mail message forma
            $mailer->setSender(array($from_addr, $from_name));
            $mailer->addRecipient($recipient);
            $mailer->setSubject($subject);
            $mailer->setBody($message);
            $mailer->addCC($copyto);
            $mailer->addBCC($bcc);
            $mailer->IsHTML($format);
            // Send notification email to administrator
            $mailer->Send();
        }
    }
}

?>