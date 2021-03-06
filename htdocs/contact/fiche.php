<?php

/* Copyright (C) 2004-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2012 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2004      Benoit Mortier       <benoit.mortier@opensides.be>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@capnetworks.com>
 * Copyright (C) 2007      Franky Van Liedekerke <franky.van.liedekerke@telenet.be>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/agenda/class/agenda.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/contact.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/lib/societe.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';

$langs->load("companies");
$langs->load("users");
$langs->load("other");
$langs->load("commercial");

$mesg = '';
$error = 0;
$errors = array();

$action = (GETPOST('action', 'alpha') ? GETPOST('action', 'alpha') : 'view');
$confirm = GETPOST('confirm', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');
$id = GETPOST('id', 'alpha');
$socid = GETPOST('socid', 'alpha');
if ($user->societe_id)
	$socid = $user->societe_id;

$object = new Contact($db);

// Get object canvas (By default, this is not defined, so standard usage of dolibarr)
$object->getCanvas($id);
$objcanvas = null;
$canvas = (!empty($object->canvas) ? $object->canvas : GETPOST("canvas"));
if (!empty($canvas)) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/canvas.class.php';
	$objcanvas = new Canvas($db, $action);
	$objcanvas->getCanvas('contact', 'contactcard', $canvas);
}

// Security check
$result = restrictedArea($user, 'contact', $id, 'socpeople&societe', '', '', '', $objcanvas); // If we create a contact with no company (shared contacts), no check on write permission
// Initialize technical object to manage hooks of thirdparties. Note that conf->hooks_modules contains array array
$hookmanager->initHooks(array('contactcard'));


/*
 * 	Actions
 */

$parameters = array('id' => $id, 'objcanvas' => $objcanvas);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);	// Note that $action and $object may have been modified by some hooks
$error = $hookmanager->error;
$errors = array_merge($errors, (array) $hookmanager->errors);

if (empty($reshook)) {
	// Cancel
	if (GETPOST("cancel") && !empty($backtopage)) {
		header("Location: " . $backtopage);
		exit;
	}

	// Creation utilisateur depuis contact
	if ($action == 'confirm_create_user' && $confirm == 'yes' && $user->rights->user->user->creer) {
		// Recuperation contact actuel
		$result = $object->fetch($id);

		if ($result > 0) {
			$db->begin();

			// Creation user
			$nuser = new User($db);
			$result = $nuser->create_from_contact($object, $_POST["login"]);

			if ($result > 0) {
				$result2 = $nuser->setPassword($user, $_POST["password"], 0, 0, 1);
				if ($result2) {
					$db->commit();
				} else {
					$error = $nuser->error;
					$errors = $nuser->errors;
					$db->rollback();
				}
			} else {
				$error = $nuser->error;
				$errors = $nuser->errors;
				$db->rollback();
			}
		} else {
			$error = $object->error;
			$errors = $object->errors;
		}
	}

	// Add contact
	if ($action == 'add' && $user->rights->societe->contact->creer) {

		if ($canvas)
			$object->canvas = $canvas;

		$object->societe->id = $_POST["socid"];
		$object->lastname = $_POST["lastname"];
		$object->firstname = $_POST["firstname"];
		$object->civilite_id = $_POST["civilite_id"];
		$object->poste = $_POST["poste"];
		$object->address = $_POST["address"];
		$object->zip = $_POST["zipcode"];
		$object->town = $_POST["town"];
		$object->country_id = $_POST["country_id"];
		$object->state_id = $_POST["departement_id"];
		$object->email = $_POST["email"];
		$object->phone_pro = $_POST["phone_pro"];
		$object->phone_perso = $_POST["phone_perso"];
		$object->phone_mobile = $_POST["phone_mobile"];
		$object->fax = $_POST["fax"];
		$object->jabberid = $_POST["jabberid"];
		$object->no_email = $_POST["no_email"];
		$object->note = $_POST["note"];

		// Note: Correct date should be completed with location to have exact GM time of birth.
		$object->birthday = dol_mktime(0, 0, 0, $_POST["birthdaymonth"], $_POST["birthdayday"], $_POST["birthdayyear"]);
		$object->birthday_alert = $_POST["birthday_alert"];

		// Get extra fields
		foreach ($_POST as $key => $value) {
			if (preg_match("/^options_/", $key)) {
				$object->array_options[$key] = GETPOST($key);
			}
		}

		if (!$_POST["lastname"]) {
			$error++;
			$errors[] = $langs->trans("ErrorFieldRequired", $langs->transnoentities("Lastname") . ' / ' . $langs->transnoentities("Label"));
			$action = 'create';
		}

		if (!$error) {
			$id = $object->create($user);
			if (is_numeric($id) && $id <= 0) {
				$error++;
				$errors = array_merge($errors, ($object->error ? array($object->error) : $object->errors));
				$action = 'create';
			}
		}

		if (!$error && is_nan($id)) {
			if (!empty($backtopage))
				$url = $backtopage;
			else
				$url = 'fiche.php?id=' . $id;
			header("Location: " . $url);
			exit;
		}
	}

	if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->societe->contact->supprimer) {
		$result = $object->fetch($_GET["id"]);

		$object->old_name = $_POST["old_name"];
		$object->old_firstname = $_POST["old_firstname"];

		$result = $object->delete();
		if ($result > 0) {
			header("Location: " . DOL_URL_ROOT . '/contact/list.php');
			exit;
		} else {
			$error = $object->error;
			$errors = $object->errors;
		}
	}

	if ($action == 'update' && !$_POST["cancel"] && $user->rights->societe->contact->creer) {
		if (empty($_POST["lastname"])) {
			$error++;
			$errors = array($langs->trans("ErrorFieldRequired", $langs->transnoentities("Name") . ' / ' . $langs->transnoentities("Label")));
			$action = 'edit';
		}

		if (!$error) {
			$object->fetch($_POST["contactid"]);

			$object->oldcopy = dol_clone($object);

			$object->old_name = $_POST["old_name"];
			$object->old_firstname = $_POST["old_firstname"];

			$object->societe->id = $_POST["socid"];
			$object->lastname = $_POST["lastname"];
			$object->firstname = $_POST["firstname"];
			$object->civilite_id = $_POST["civilite_id"];
			$object->poste = $_POST["poste"];

			$object->address = $_POST["address"];
			$object->zip = $_POST["zipcode"];
			$object->town = $_POST["town"];
			$object->state_id = $_POST["departement_id"];
			$object->country_id = $_POST["country_id"];

			$object->email = $_POST["email"];
			$object->phone_pro = $_POST["phone_pro"];
			$object->phone_perso = $_POST["phone_perso"];
			$object->phone_mobile = $_POST["phone_mobile"];
			$object->fax = $_POST["fax"];
			$object->jabberid = $_POST["jabberid"];
			$object->no_email = $_POST["no_email"];
			$object->note = $_POST["note"];

			// Get extra fields
			foreach ($_POST as $key => $value) {
				if (preg_match("/^options_/", $key)) {
					$object->array_options[$key] = GETPOST($key);
				}
			}

			$result = $object->update($_POST["contactid"], $user);

			if ($result > 0) {
				$object->old_name = '';
				$object->old_firstname = '';
				$action = 'view';
			} else {
				$error = $object->error;
				$errors = $object->errors;
				$action = 'edit';
			}
		}
	}
}


/*
 * 	View
 */


llxHeader('', $langs->trans("ContactsAddresses"));

$form = new Form($db);
$formcompany = new FormCompany($db);

$countrynotdefined = $langs->trans("ErrorSetACountryFirst") . ' (' . $langs->trans("SeeAbove") . ')';

if (!empty($socid)) {
	$objsoc = new Societe($db);
	$objsoc->fetch($socid);
}

if (is_object($objcanvas) && $objcanvas->displayCanvasExists($action)) {
	// -----------------------------------------
	// When used with CANVAS
	// -----------------------------------------
	if (empty($object->error) && $id) {
		$object = new Contact($db);
		$object->fetch($id);
	}
	$objcanvas->assign_values($action, $id); // Set value for templates
	$objcanvas->display_canvas($action);  // Show template
} else {
	// -----------------------------------------
	// When used in standard mode
	// -----------------------------------------
	// Confirm deleting contact
	if ($user->rights->societe->contact->supprimer) {
		if ($action == 'delete') {
			$ret = $form->form_confirm($_SERVER["PHP_SELF"] . "?id=" . $_GET["id"], $langs->trans("DeleteContact"), $langs->trans("ConfirmDeleteContact"), "confirm_delete", '', 0, 1);
			if ($ret == 'html')
				print '<br>';
		}
	}

	/*
	 * Onglets
	 */
	if (!empty($id)) {
		// Si edition contact deja existant
		$object = new Contact($db);
		$res = $object->fetch($id);

		// Show tabs
		//$head = contact_prepare_head($object);

		$title = (!empty($conf->global->SOCIETE_ADDRESSES_MANAGEMENT) ? $langs->trans("Contacts") : $langs->trans("ContactsAddresses"));
		print_fiche_titre($object->firstname . " " . $object->lastname);
		print '<div class="with-padding">';
		print '<div class="columns">';

		print column_start();
		//print start_box($title, $object->fk_extrafields->ico);

		dol_fiche_head($head, 'card', $title, 0, 'contact');
	}

	if ($user->rights->societe->contact->creer) {
		if ($action == 'create') {
			/*
			 * Fiche en mode creation
			 */
			$object->canvas = $canvas;

			$object->state_id = $_POST["state_id"];

			// We set country_id, country_code and label for the selected country
			$object->country_id = $_POST["country_id"] ? $_POST["country_id"] : $mysoc->country_id;

			$title = $addcontact = (!empty($conf->global->SOCIETE_ADDRESSES_MANAGEMENT) ? $langs->trans("AddContact") : $langs->trans("AddContactAddress"));
			print_fiche_titre($title);
			print '<div class="with-padding">';
			print '<div class="columns">';

			// Affiche les erreurs
			dol_htmloutput_errors(is_numeric($error) ? '' : $error, $errors);

			print column_start();

			print "\n" . '<script type="text/javascript" language="javascript">' . "\n";
			print 'jQuery(document).ready(function () {
							jQuery("#selectcountry_id").change(function() {
								document.formsoc.action.value="create";
								document.formsoc.submit();
							});

							$("#copyaddressfromsoc").click(function() {
								$(\'textarea[name="address"]\').text("' . addslashes($objsoc->address) . '");
								$(\'input[name="zipcode"]\').val("' . addslashes($objsoc->zip) . '");
								$(\'input[name="town"]\').val("' . addslashes($objsoc->town) . '");
								$(\'select[name="country_id"]\').val("' . addslashes($objsoc->country_id) . '");
								$(\'select[name="state_id"]\').val("' . addslashes($objsoc->state_id) . '");
            				});
						})' . "\n";
			print '</script>' . "\n";

			print '<form method="post" name="formsoc" action="' . $_SERVER["PHP_SELF"] . '">';
			print '<input type="hidden" name="token" value="' . $_SESSION['newtoken'] . '">';
			print '<input type="hidden" name="action" value="add">';
			print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';

			print '<table class="border" width="100%">';

			// Name
			print '<tr><td width="20%" class="fieldrequired">' . $langs->trans("Lastname") . ' / ' . $langs->trans("Label") . '</td><td width="30%"><input name="lastname" type="text" size="30" maxlength="80" value="' . (isset($_POST["lastname"]) ? $_POST["lastname"] : $object->lastname) . '"></td>';
			print '<td width="20%">' . $langs->trans("Firstname") . '</td><td width="30%"><input name="firstname" type="text" size="30" maxlength="80" value="' . (isset($_POST["firstname"]) ? $_POST["firstname"] : $object->firstname) . '"></td></tr>';

			// Company
			if (empty($conf->global->SOCIETE_DISABLE_CONTACTS)) {
				if (!empty($socid)) {
					print '<tr><td>' . $langs->trans("Company") . '</td>';
					print '<td colspan="3">';
					print $objsoc->getNomUrl(1);
					print '</td>';
					print '<input type="hidden" name="socid" value="' . $objsoc->id . '">';
					print '</td></tr>';
				} else {
					print '<tr><td>' . $langs->trans("Company") . '</td><td colspan="3">';
					print $object->select_fk_extrafields('societe', 'socid', null, true, 25);
					print '</td></tr>';
				}
			}

			// Civility
			print '<tr><td width="15%">' . $langs->trans("UserTitle") . '</td><td colspan="3">';
			print $object->select_fk_extrafields("civilite_id", "civilite_id");
			print '</td></tr>';

			print '<tr><td>' . $langs->trans("PostOrFunction") . '</td><td colspan="3"><input name="poste" type="text" size="50" maxlength="80" value="' . (isset($_POST["poste"]) ? $_POST["poste"] : $object->poste) . '"></td>';

			// Address
			if (($objsoc->typent_code == 'TE_PRIVATE' || !empty($conf->global->CONTACT_USE_COMPANY_ADDRESS)) && dol_strlen(trim($object->address)) == 0)
				$object->address = $objsoc->address; // Predefined with third party
			print '<tr><td>' . $langs->trans("Address");
			print '</td><td colspan="2"><textarea class="flat" name="address" cols="70">' . (isset($_POST["address"]) ? $_POST["address"] : $object->address) . '</textarea></td>';

			$rowspan = 3;
			if (empty($conf->global->SOCIETE_DISABLE_STATE))
				$rowspan++;

			print '<td valign="middle" align="center" rowspan="' . $rowspan . '">';
			if ($conf->use_javascript_ajax && $socid)
				print '<a href="#" id="copyaddressfromsoc">' . $langs->trans('CopyAddressFromSoc') . '</a>';
			print '</td>';
			print '</tr>';

			// Zip / Town
			if (($objsoc->typent_code == 'TE_PRIVATE' || !empty($conf->global->CONTACT_USE_COMPANY_ADDRESS)) && dol_strlen(trim($object->zip)) == 0)
				$object->zip = $objsoc->zip;   // Predefined with third party
			if (($objsoc->typent_code == 'TE_PRIVATE' || !empty($conf->global->CONTACT_USE_COMPANY_ADDRESS)) && dol_strlen(trim($object->town)) == 0)
				$object->town = $objsoc->town; // Predefined with third party
			print '<tr><td>' . $langs->trans("Zip") . ' / ' . $langs->trans("Town") . '</td><td colspan="2">';
			print $formcompany->select_ziptown((isset($_POST["zipcode"]) ? $_POST["zipcode"] : $object->zip), 'zipcode', array('town', 'selectcountry_id', 'state_id'), 6) . '&nbsp;';
			print $formcompany->select_ziptown((isset($_POST["town"]) ? $_POST["town"] : $object->town), 'town', array('zipcode', 'selectcountry_id', 'state_id'));
			print '</td></tr>';

			// Country
			if (dol_strlen(trim($object->country_id)) == 0)
				$object->country_id = $objsoc->country_id; // Predefined with third party

			print '<tr><td>' . $langs->trans("Country") . '</td><td colspan="2">';
			print $object->select_fk_extrafields("country_id", "country_id");
			if ($user->admin)
				print info_admin($langs->trans("YouCanChangeValuesForThisListFromDictionnarySetup"), 1);
			print '</td></tr>';

			// State
			if (empty($conf->global->SOCIETE_DISABLE_STATE)) {
				print '<tr><td>' . $langs->trans('State') . '</td><td colspan="2">';
				if ($object->country_id) {
					print $object->select_fk_extrafields("state_id", 'state_id');
				} else {
					print $countrynotdefined;
				}
				print '</td></tr>';
			}

			// Phone / Fax
			if (($objsoc->typent_code == 'TE_PRIVATE' || !empty($conf->global->CONTACT_USE_COMPANY_ADDRESS)) && dol_strlen(trim($object->phone_pro)) == 0)
				$object->phone_pro = $objsoc->tel; // Predefined with third party
			print '<tr><td>' . $langs->trans("PhonePro") . '</td><td><input name="phone_pro" type="text" size="18" maxlength="80" value="' . (isset($_POST["phone_pro"]) ? $_POST["phone_pro"] : $object->phone_pro) . '"></td>';
			print '<td>' . $langs->trans("PhonePerso") . '</td><td><input name="phone_perso" type="text" size="18" maxlength="80" value="' . (isset($_POST["phone_perso"]) ? $_POST["phone_perso"] : $object->phone_perso) . '"></td></tr>';

			if (($objsoc->typent_code == 'TE_PRIVATE' || !empty($conf->global->CONTACT_USE_COMPANY_ADDRESS)) && dol_strlen(trim($object->fax)) == 0)
				$object->fax = $objsoc->fax; // Predefined with third party
			print '<tr><td>' . $langs->trans("PhoneMobile") . '</td><td><input name="phone_mobile" type="text" size="18" maxlength="80" value="' . (isset($_POST["phone_mobile"]) ? $_POST["phone_mobile"] : $object->phone_mobile) . '"></td>';
			print '<td>' . $langs->trans("Fax") . '</td><td><input name="fax" type="text" size="18" maxlength="80" value="' . (isset($_POST["fax"]) ? $_POST["fax"] : $object->fax) . '"></td></tr>';

			// EMail
			if (($objsoc->typent_code == 'TE_PRIVATE' || !empty($conf->global->CONTACT_USE_COMPANY_ADDRESS)) && dol_strlen(trim($object->email)) == 0)
				$object->email = $objsoc->email; // Predefined with third party
			print '<tr><td>' . $langs->trans("Email") . '</td><td><input name="email" type="text" size="40" maxlength="80" value="' . (isset($_POST["email"]) ? $_POST["email"] : $object->email) . '"></td>';
			if (!empty($conf->mailing->enabled)) {
				print '<td>' . $langs->trans("No_Email") . '</td><td>' . $form->selectyesno('no_email', (isset($_POST["no_email"]) ? $_POST["no_email"] : $object->no_email), 1) . '</td>';
			} else {
				print '<td colspan="2">&nbsp;</td>';
			}
			print '</tr>';

			// Instant message and no email
			print '<tr><td>' . $langs->trans("IM") . '</td><td colspan="3"><input name="jabberid" type="text" size="50" maxlength="80" value="' . (isset($_POST["jabberid"]) ? $_POST["jabberid"] : $object->jabberid) . '"></td></tr>';

			// Note
			print '<tr><td valign="top">' . $langs->trans("Note") . '</td><td colspan="3" valign="top"><textarea name="note" cols="70" rows="' . ROWS_3 . '">' . (isset($_POST["note"]) ? $_POST["note"] : $object->note) . '</textarea></td></tr>';

			// Other attributes
			$parameters = array('colspan' => ' colspan="3"');
			$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action);	// Note that $action and $object may have been modified by hook
			if (empty($reshook)) {
				foreach ($object->fk_extrafields->fields as $key => $aRow) {
					if ($aRow->optional && $aRow->enable) {
						$value = (isset($_POST["options_" . $key]) ? $_POST["options_" . $key] : (isset($object->array_options["options_" . $key]) ? $object->array_options["options_" . $key] : ''));
						print '<tr><td>' . $aRow->label . '</td><td colspan="3">';
						print $object->fk_extrafields->showInputField($key, $value);
						print '</td></tr>' . "\n";
					}
				}
			}

			print "</table><br>";


			// Add personnal information
			print_fiche_titre('<div class="comboperso">' . $langs->trans("PersonalInformations") . '</div>', '', '');

			print '<table class="border" width="100%">';

			// Date To Birth
			print '<tr><td width="20%">' . $langs->trans("DateToBirth") . '</td><td width="30%">';
			$form = new Form($db);
			if ($object->birthday) {
				print $form->select_date($object->birthday, 'birthday', 0, 0, 0, "perso");
			} else {
				print $form->select_date('', 'birthday', 0, 0, 1, "perso");
			}
			print '</td>';

			print '<td colspan="2">' . $langs->trans("Alert") . ': ';
			if ($object->birthday_alert) {
				print '<input type="checkbox" name="birthday_alert" checked></td>';
			} else {
				print '<input type="checkbox" name="birthday_alert"></td>';
			}
			print '</tr>';

			print "</table><br><br>";


			print '<center>';
			print '<input type="submit" class="button" name="add" value="' . $langs->trans("Add") . '">';
			if (!empty($backtopage)) {
				print ' &nbsp; &nbsp; ';
				print '<input type="submit" class="button" name="cancel" value="' . $langs->trans("Cancel") . '">';
			}
			print '</center>';

			print "</form>";
			print column_end();
		} elseif ($action == 'edit' && !empty($id)) {
			/*
			 * Fiche en mode edition
			 */

			// Affiche les erreurs
			dol_htmloutput_errors($error, $errors);

			print "\n" . '<script type="text/javascript" language="javascript">' . "\n";
			print 'jQuery(document).ready(function () {
							jQuery("#selectcountry_id").change(function() {
								document.formsoc.action.value="edit";
								document.formsoc.submit();
							});

							$("#copyaddressfromsoc").click(function() {
								$(\'textarea[name="address"]\').text("' . addslashes($objsoc->address) . '");
								$(\'input[name="zipcode"]\').val("' . addslashes($objsoc->zip) . '");
								$(\'input[name="town"]\').val("' . addslashes($objsoc->town) . '");
								$(\'select[name="country_id"]\').val("' . addslashes($objsoc->country_id) . '");
								$(\'select[name="state_id"]\').val("' . addslashes($objsoc->state_id) . '");
                                                                return false;
            				});
						})' . "\n";
			print '</script>' . "\n";

			print '<form method="post" action="' . $_SERVER["PHP_SELF"] . '?id=' . $id . '" name="formsoc">';
			print '<input type="hidden" name="token" value="' . $_SESSION['newtoken'] . '">';
			print '<input type="hidden" name="id" value="' . $id . '">';
			print '<input type="hidden" name="action" value="update">';
			print '<input type="hidden" name="contactid" value="' . $object->id . '">';
			print '<input type="hidden" name="old_name" value="' . $object->name . '">';
			print '<input type="hidden" name="old_firstname" value="' . $object->firstname . '">';
			if (!empty($backtopage))
				print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';

			print '<table class="border" width="100%">';

			// Ref
			print '<tr><td>' . $langs->trans("Ref") . '</td><td colspan="3">';
			print $form->showrefnav($object, 'ref', '', 1, 'ref');
			print '</td></tr>';

			// Name
			print '<tr><td width="20%" class="fieldrequired">' . $langs->trans("Lastname") . ' / ' . $langs->trans("Label") . '</td><td width="30%"><input name="lastname" type="text" size="20" maxlength="80" value="' . (isset($_POST["lastname"]) ? $_POST["lastname"] : $object->lastname) . '"></td>';
			print '<td width="20%">' . $langs->trans("Firstname") . '</td><td width="30%"><input name="firstname" type="text" size="20" maxlength="80" value="' . (isset($_POST["firstname"]) ? $_POST["firstname"] : $object->firstname) . '"></td></tr>';

			// Company
			if (empty($conf->global->SOCIETE_DISABLE_CONTACTS)) {
				print '<tr><td>' . $langs->trans("Company") . '</td>';
				print '<td colspan="3">';
				print $object->select_fk_extrafields('societe', 'socid');
				print '</td>';
				print '</tr>';
			}

			// Civility
			print '<tr><td>' . $langs->trans("UserTitle") . '</td><td colspan="3">';
			print $object->select_fk_extrafields("civilite_id", "civilite_id");
			print '</td></tr>';

			print '<tr><td>' . $langs->trans("PostOrFunction") . '</td><td colspan="3"><input name="poste" type="text" size="50" maxlength="80" value="' . (isset($_POST["poste"]) ? $_POST["poste"] : $object->poste) . '"></td></tr>';

			// Address
			print '<tr><td>' . $langs->trans("Address");
			print '</td><td colspan="2"><textarea class="flat" name="address" cols="70">' . (isset($_POST["address"]) ? $_POST["address"] : $object->address) . '</textarea></td>';

			$rowspan = 3;
			if (empty($conf->global->SOCIETE_DISABLE_STATE))
				$rowspan++;

			print '<td valign="middle" align="center" rowspan="' . $rowspan . '">';
			if ($conf->use_javascript_ajax)
				print '<a href="" id="copyaddressfromsoc">' . $langs->trans('CopyAddressFromSoc') . '</a>';
			print '</td></tr>';

			// Zip / Town
			print '<tr><td>' . $langs->trans("Zip") . ' / ' . $langs->trans("Town") . '</td><td colspan="2">';
			print $formcompany->select_ziptown((isset($_POST["zipcode"]) ? $_POST["zipcode"] : $object->zip), 'zipcode', array('town', 'selectcountry_id', 'state_id'), 6) . '&nbsp;';
			print $formcompany->select_ziptown((isset($_POST["town"]) ? $_POST["town"] : $object->town), 'town', array('zipcode', 'selectcountry_id', 'state_id'));
			print '</td></tr>';

			// Country
			print '<tr><td>' . $langs->trans("Country") . '</td><td colspan="2">';
			print $object->select_fk_extrafields("country_id", "country_id");
			if ($user->admin)
				print info_admin($langs->trans("YouCanChangeValuesForThisListFromDictionnarySetup"), 1);
			print '</td></tr>';

			// State
			if (empty($conf->global->SOCIETE_DISABLE_STATE)) {
				print '<tr><td>' . $langs->trans('State') . '</td><td colspan="2">';
				print $object->select_fk_extrafields("state_id", 'state_id');
				print '</td></tr>';
			}

			// Phone
			print '<tr><td>' . $langs->trans("PhonePro") . '</td><td><input name="phone_pro" type="text" size="18" maxlength="80" value="' . (isset($_POST["phone_pro"]) ? $_POST["phone_pro"] : $object->phone_pro) . '"></td>';
			print '<td>' . $langs->trans("PhonePerso") . '</td><td><input name="phone_perso" type="text" size="18" maxlength="80" value="' . (isset($_POST["phone_perso"]) ? $_POST["phone_perso"] : $object->phone_perso) . '"></td></tr>';

			print '<tr><td>' . $langs->trans("PhoneMobile") . '</td><td><input name="phone_mobile" type="text" size="18" maxlength="80" value="' . (isset($_POST["phone_mobile"]) ? $_POST["phone_mobile"] : $object->phone_mobile) . '"></td>';
			print '<td>' . $langs->trans("Fax") . '</td><td><input name="fax" type="text" size="18" maxlength="80" value="' . (isset($_POST["fax"]) ? $_POST["fax"] : $object->fax) . '"></td></tr>';

			// EMail
			print '<tr><td>' . $langs->trans("EMail") . '</td><td><input name="email" type="text" size="40" maxlength="80" value="' . (isset($_POST["email"]) ? $_POST["email"] : $object->email) . '"></td>';
			if (!empty($conf->mailing->enabled)) {
				$langs->load("mails");
				print '<td nowrap>' . $langs->trans("NbOfEMailingsReceived") . '</td>';
				print '<td>' . $object->getNbOfEMailings() . '</td>';
			} else {
				print '<td colspan="2">&nbsp;</td>';
			}
			print '</tr>';

			// Jabberid
			print '<tr><td>Jabberid</td><td><input name="jabberid" type="text" size="40" maxlength="80" value="' . (isset($_POST["jabberid"]) ? $_POST["jabberid"] : $object->jabberid) . '"></td>';
			if (!empty($conf->mailing->enabled)) {
				print '<td>' . $langs->trans("No_Email") . '</td><td>' . $form->selectyesno('no_email', (isset($_POST["no_email"]) ? $_POST["no_email"] : $object->no_email), 1) . '</td>';
			} else {
				print '<td colspan="2">&nbsp;</td>';
			}
			print '</tr>';

			// Other attributes
			$parameters = array('colspan' => ' colspan="3"');
			$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action);	// Note that $action and $object may have been modified by hook
			if (empty($reshook)) {
				foreach ($object->fk_extrafields->fields as $key => $aRow) {
					if ($aRow->optional && $aRow->enable) {
						$value = (isset($_POST["options_" . $key]) ? $_POST["options_" . $key] : (isset($object->array_options["options_" . $key]) ? $object->array_options["options_" . $key] : ''));
						print '<tr><td>' . $aRow->label . '</td><td colspan="3">';
						print $object->fk_extrafields->showInputField($key, $value);
						print '</td></tr>' . "\n";
					}
				}
			}

			$object->load_ref_elements();

			if (!empty($conf->commande->enabled)) {
				print '<tr><td>' . $langs->trans("ContactForOrders") . '</td><td colspan="3">';
				print $object->ref_commande ? $object->ref_commande : $langs->trans("NoContactForAnyOrder");
				print '</td></tr>';
			}

			if (!empty($conf->propal->enabled)) {
				print '<tr><td>' . $langs->trans("ContactForProposals") . '</td><td colspan="3">';
				print $object->ref_propal ? $object->ref_propal : $langs->trans("NoContactForAnyProposal");
				print '</td></tr>';
			}

			if (!empty($conf->contrat->enabled)) {
				print '<tr><td>' . $langs->trans("ContactForContracts") . '</td><td colspan="3">';
				print $object->ref_contrat ? $object->ref_contrat : $langs->trans("NoContactForAnyContract");
				print '</td></tr>';
			}

			if (!empty($conf->facture->enabled)) {
				print '<tr><td>' . $langs->trans("ContactForInvoices") . '</td><td colspan="3">';
				print $object->ref_facturation ? $object->ref_facturation : $langs->trans("NoContactForAnyInvoice");
				print '</td></tr>';
			}

			// Login Dolibarr
			print '<tr><td>' . $langs->trans("SpeedealingLogin") . '</td><td colspan="3">';
			if ($object->user_id) {
				$dolibarr_user = new User($db);
				$result = $dolibarr_user->fetch($object->user_id);
				print $dolibarr_user->getLoginUrl(1);
			}
			else
				print $langs->trans("NoSpeedealingAccess");
			print '</td></tr>';

			print '</table><br>';

			print '<center>';
			print '<input type="submit" class="button" name="save" value="' . $langs->trans("Save") . '">';
			print ' &nbsp; ';
			print '<input type="submit" class="button" name="cancel" value="' . $langs->trans("Cancel") . '">';
			print '</center>';

			print "</form>";

			print column_end();
		}
	}

	if (!empty($id) && $action != 'edit' && $action != 'create') {
		$objsoc = new Societe($db);

		/*
		 * Fiche en mode visualisation
		 */

		dol_htmloutput_errors($error, $errors);

		if ($action == 'create_user') {
			// Full firstname and name separated with a dot : firstname.name
			include_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
			$login = dol_buildlogin($object->nom, $object->prenom);

			$generated_password = '';
			if (!$ldap_sid) { // TODO ldap_sid ?
				require_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';
				$generated_password = getRandomPassword('');
			}
			$password = $generated_password;

			// Create a form array
			$formquestion = array(
				array('label' => $langs->trans("LoginToCreate"), 'type' => 'text', 'name' => 'login', 'value' => $login),
				array('label' => $langs->trans("Password"), 'type' => 'text', 'name' => 'password', 'value' => $password),
					//array('label' => $form->textwithpicto($langs->trans("Type"),$langs->trans("InternalExternalDesc")), 'type' => 'select', 'name' => 'intern', 'default' => 1, 'values' => array(0=>$langs->trans('Internal'),1=>$langs->trans('External')))
			);
			$text = $langs->trans("ConfirmCreateContact") . '<br>';
			if (!empty($conf->societe->enabled)) {
				if ($object->societe->id > 0)
					$text.=$langs->trans("UserWillBeExternalUser");
				else
					$text.=$langs->trans("UserWillBeInternalUser");
			}
			$ret = $form->form_confirm($_SERVER["PHP_SELF"] . "?id=" . $object->id, $langs->trans("CreateSpeedealingLogin"), $text, "confirm_create_user", $formquestion, 'yes');
			if ($ret == 'html')
				print '<br>';
		}

		print '<table class="border" width="100%">';

		$linkback = '<a href="' . DOL_URL_ROOT . '/contact/list.php">' . $langs->trans("BackToList") . '</a>';

		// Ref
		print '<tr><td width="20%">' . $langs->trans("Ref") . '</td><td colspan="3">';
		print $form->showrefnav($object, 'id', $linkback);
		print '</td></tr>';

		// Name
		print '<tr><td width="20%">' . $langs->trans("Lastname") . ' / ' . $langs->trans("Label") . '</td><td width="30%">' . $object->lastname . '</td>';
		print '<td width="20%">' . $langs->trans("Firstname") . '</td><td width="30%">' . $object->firstname . '</td></tr>';

		// Company
		if (empty($conf->global->SOCIETE_DISABLE_CONTACTS)) {
			print '<tr><td>' . $langs->trans("Company") . '</td><td colspan="3">';
			if (!empty($object->societe->id)) {
				print $object->print_fk_extrafields("societe");
			} else {
				print $langs->trans("ContactNotLinkedToCompany");
			}
			print '</td></tr>';
		}

		// Civility
		print '<tr><td width="15%">' . $langs->trans("UserTitle") . '</td><td colspan="3">';
		print $object->print_fk_extrafields("civilite_id");
		print '</td></tr>';

		// Role
		print '<tr><td>' . $langs->trans("PostOrFunction") . '</td><td colspan="3">' . $object->poste . '</td>';

		// Address
		print '<tr><td>' . $langs->trans("Address") . '</td><td colspan="3">';
		dol_print_address($object->address, 'gmap', 'contact', $object->id);
		print '</td></tr>';

		// Zip Town
		print '<tr><td>' . $langs->trans("Zip") . ' / ' . $langs->trans("Town") . '</td><td colspan="3">';
		print $object->zip;
		if ($object->zip)
			print '&nbsp;';
		print $object->town . '</td></tr>';

		// Country
		print '<tr><td>' . $langs->trans("Country") . '</td><td colspan="3">';
		$img = picto_from_langcode($object->country_id);
		if ($img)
			print $img . ' ';
		print $object->print_fk_extrafields("country_id");
		print '</td></tr>';

		// State
		if (empty($conf->global->SOCIETE_DISABLE_STATE)) {
			print '<tr><td>' . $langs->trans('State') . '</td><td colspan="3">' . $object->print_fk_extrafields("state_id") . '</td>';
		}

		// Phone
		print '<tr><td>' . $langs->trans("PhonePro") . '</td><td>' . dol_print_phone($object->phone_pro, $object->country_code, $object->id, $object->societe->id, 'AC_TEL') . '</td>';
		print '<td>' . $langs->trans("PhonePerso") . '</td><td>' . dol_print_phone($object->phone_perso, $object->country_code, $object->id, $object->societe->id, 'AC_TEL') . '</td></tr>';

		print '<tr><td>' . $langs->trans("PhoneMobile") . '</td><td>' . dol_print_phone($object->phone_mobile, $object->country_code, $object->id, $object->societe->id, 'AC_TEL') . '</td>';
		print '<td>' . $langs->trans("Fax") . '</td><td>' . dol_print_phone($object->fax, $object->country_code, $object->id, $object->societe->id, 'AC_FAX') . '</td></tr>';

		// Email
		print '<tr><td>' . $langs->trans("EMail") . '</td><td>' . dol_print_email($object->email, $object->id, $object->societe->id, 'AC_EMAIL') . '</td>';
		if (!empty($conf->mailing->enabled)) {
			$langs->load("mails");
			print '<td nowrap>' . $langs->trans("NbOfEMailingsReceived") . '</td>';
			print '<td><a href="' . DOL_URL_ROOT . '/comm/mailing/liste.php?filteremail=' . urlencode($object->email) . '">' . $object->getNbOfEMailings() . '</a></td>';
		} else {
			print '<td colspan="2">&nbsp;</td>';
		}
		print '</tr>';

		// Instant message and no email
		print '<tr><td>' . $langs->trans("IM") . '</td><td>' . $object->jabberid . '</td>';
		if (!empty($conf->mailing->enabled)) {
			print '<td>' . $langs->trans("No_Email") . '</td><td>' . yn($object->no_email) . '</td>';
		} else {
			print '<td colspan="2">&nbsp;</td>';
		}
		print '</tr>';

		// Tag
		print '<tr><td>' . $form->editfieldkey("Categories", 'Tag', $object->Tag, $object, $user->rights->societe->creer, "tag") . '</td><td colspan="' . (2 + (($showlogo || $showbarcode) ? 0 : 1)) . '">';
		print $form->editfieldval("Categories", 'Tag', $object->Tag, $object, $user->rights->societe->creer, "tag");
		print "</td></tr>";

		// Other attributes
		$parameters = array('socid' => $socid, 'colspan' => ' colspan="3"');
		$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action);	// Note that $action and $object may have been modified by hook
		if (empty($reshook)) {
			foreach ($object->fk_extrafields->fields as $key => $aRow) {
				if ($aRow->optional && $aRow->enable) {
					$value = (isset($_POST["options_" . $key]) ? $_POST["options_" . $key] : (isset($object->array_options["options_" . $key]) ? $object->array_options["options_" . $key] : ''));
					print '<tr><td>' . $aRow->label . '</td><td colspan="3">';
					print $object->fk_extrafields->showOutputField($key, $value);
					print '</td></tr>' . "\n";
				}
			}
		}

		$object->load_ref_elements();

		if (!empty($conf->commande->enabled)) {
			print '<tr><td>' . $langs->trans("ContactForOrders") . '</td><td colspan="3">';
			print $object->ref_commande ? $object->ref_commande : $langs->trans("NoContactForAnyOrder");
			print '</td></tr>';
		}

		if (!empty($conf->propal->enabled)) {
			print '<tr><td>' . $langs->trans("ContactForProposals") . '</td><td colspan="3">';
			print $object->ref_propal ? $object->ref_propal : $langs->trans("NoContactForAnyProposal");
			print '</td></tr>';
		}

		if (!empty($conf->contrat->enabled)) {
			print '<tr><td>' . $langs->trans("ContactForContracts") . '</td><td colspan="3">';
			print $object->ref_contrat ? $object->ref_contrat : $langs->trans("NoContactForAnyContract");
			print '</td></tr>';
		}

		if (!empty($conf->facture->enabled)) {
			print '<tr><td>' . $langs->trans("ContactForInvoices") . '</td><td colspan="3">';
			print $object->ref_facturation ? $object->ref_facturation : $langs->trans("NoContactForAnyInvoice");
			print '</td></tr>';
		}

		print '<tr><td>' . $langs->trans("SpeedealingLogin") . '</td><td colspan="3">';
		if ($object->user_id) {
			$dolibarr_user = new User($db);
			$result = $dolibarr_user->fetch($object->user_id);
			print $dolibarr_user->getLoginUrl(1);
		}
		else
			print $langs->trans("NoSpeedealingAccess");
		print '</td></tr>';

		print "</table>";

		print "</div>";

		// Barre d'actions
		if (!$user->societe_id) {
			print '<div class="tabsAction">';

			if ($user->rights->societe->contact->creer) {
				print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&amp;action=edit">' . $langs->trans('Modify') . '</a>';
			}

			if (!$object->user_id && $user->rights->user->user->creer) {
				print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&amp;action=create_user">' . $langs->trans("CreateSpeedealingLogin") . '</a>';
			}

			if ($user->rights->societe->contact->supprimer) {
				print '<a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&amp;action=delete">' . $langs->trans('Delete') . '</a>';
			}

			print "</div>";
		}

		print column_end();

		print column_start("six");
		print $object->show_notes(true);
		print column_end();

		$agenda = new Agenda($db);
		print column_start("six");
		print $agenda->show($object->id, 25);
		print column_end();
	}
}

print '</div></div>';

llxFooter();
?>