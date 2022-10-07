<?php

use WHMCS\CustomField;
use WHMCS\Service\Addon;
use WHMCS\Module\Gateway;
use WHMCS\Billing\Invoice;
use WHMCS\Service\Service;
use WHMCS\Authentication\CurrentUser;
use WHMCS\CustomField\CustomFieldValue;
use WHMCS\Billing\Invoice\Item as InvoiceItem;

/**
 * Service Cancellation Helper Hook
 *
 * A helper that automatically processes everything related to the cancellation of a service.
 * 
 * Adds a note to the service referencing the date, admin user and ticket ID (if supplied in the custom field) to the service.
 * 
 * Cancels all related addons and also adds a note to them with similar to the above note on the service.
 * 
 * Cancels any related unpaid invoices and if those invoices have other services on them, the invoice gets split and a new invoice
 * is created for the unrelated services. A new invoice email is also sent out. 
 * 
 *
 * @package    WHMCS
 * @author     Lee Mahoney <lee@leemahoney.dev>
 * @copyright  Copyright (c) Lee Mahoney 2022
 * @license    MIT License
 * @version    1.0.3
 * @link       https://leemahoney.dev
 */


if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

$customFieldName            = 'Cancellation Ticket ID';
$allowCancellationOfAddons  = true;

/**
 * invoice_cancellation_helper_event
 *
 * @param  mixed $vars
 * @return void
 */
function invoice_cancellation_helper_event($vars) {

    # To hold addons related to the service
    $addons = [];

    # Grab information on the service
    $serviceData = Service::where('id', $vars['serviceid'])->first();

    # Let's check to see if the service isn't already cancelled, if it is then the rest of this hook can be skipped.

    $currentStatus  = $serviceData->domainstatus;
    $newStatus      = $_POST['domainstatus'];

    if ($newStatus == "Cancelled" && $currentStatus != "Cancelled") { 

        # Loop through related addons and add them to array
        foreach (Addon::where('hostingid', $vars['serviceid'])->get() as $addon) {
            
            if (!$allowCancellationOfAddons) {
                continue;
            }

            $addons[] = $addon->id;

        }

        # Note keeping, let's grab a couple of things we'll need to make the note.
        
        $fieldID        = CustomField::where(['fieldname' => $customFieldName, 'type' => 'product'])->first()->id;
        $ticketID       = CustomFieldValue::where(['fieldid' => $fieldID, 'relid' => $vars['serviceid']])->first()->value;
        $date           = date('d/m/Y');
        $currentUser    = new CurrentUser;
        $username       = $currentUser->admin()->username;

        # Check if ticket ID was entered into the custom field
        if ($ticketID) {

            $notes = "\nService cancelled by {$username} on {$date} through ticket {$ticketID}";
        
        } else if(!empty($_POST['customfield'][$fieldID])) {

            $field  = htmlspecialchars($_POST['customfield'][$fieldID]);
            $notes = "\nService cancelled by {$username} on {$date} through ticket {$field}";

        } else {
            $notes = "\nService cancelled by {$username} on {$date}";
        }

        # Workaround for the notes, can't really think of a better way to do this yet.
        $_SESSION['csh_notes'] = $notes;
        
        if ($serviceData->subscriptionid) {
            
            $gateway = new Gateway;

            $gateway->load($serviceData->paymentmethod);

            if ($gateway->functionExists('cancelSubscription')) {
                $gateway->call('cancelSubscription', ['subscriptionID' => $serviceData->subscriptionid]);
            }

            Service::where('id', $serviceData->id)->update([
                'subscriptionid' => ''
            ]);

        }

        # check and see if there are any addons related to main service
        if (!empty($addons)) {

            # if so loop through them and cancelled them while adding a note
            foreach (Addon::where('hostingid', $vars['serviceid'])->get() as $addon) {

                # only cancel if they aren't already cancelled
                if ($addon->status == 'Cancelled') { 
                    continue;
                }

                # Get current notes on the addon module
                $notes = $addon->notes;

                # Again check if a ticket ID was entered on the MAIN SERVICE custom field. Not related to the ticket ID custom field on the addon, thats only for cancelling the addons individually.
                if ($ticketID) {

                    $notes .= "\nService cancelled by {$username} on {$date} through ticket {$ticketID}";
                
                } else if(!empty($_POST['customfield'][$fieldID])) {
        
                    $field  = htmlspecialchars($_POST['customfield'][$fieldID]);
                    $notes .= "\nService cancelled by {$username} on {$date} through ticket {$field}";

                } else {
                    $notes .= "\nService cancelled by {$username} on {$date}";
                }
                
                # Cancel the addon and update the notes
                try {

                    Addon::where('id', $addon->id)->update([
                        'status'            => 'Cancelled',
                        'termination_date'  => date('Y-m-d'),
                        'notes'             => $notes
                    ]);
                    
                } catch (\Exception $e) {
                    logActivity("Failed to cancel addon #{$addon->id}. Reason: {$e->getMessage()}", $addon->userid);
                }

                if ($addon->subscriptionid) {

                    $gateway = new Gateway;

                    $gateway->load($addon->paymentmethod);

                    if ($gateway->functionExists('cancelSubscription')) {
                        $gateway->call('cancelSubscription', ['subscriptionID' => $addon->subscriptionid]);
                    }

                    Addon::where('id', $addon->id)->update([
                        'subscriptionid' => ''
                    ]);

                }

            }

        }

        # loop through invoices for the user that are set as unpaid
        foreach (Invoice::where(['userid' => $serviceData->userid, 'status' => 'Unpaid'])->get() as $userInvoice) {
            
            # Create two arrays, one to hold related invoice items (to the addon. Actually it should just be the addon ^_^) and one to hold all unrelated invoice items
            $related    = [];
            $unrelated  = [];

            # Reset the count otherwise the logic will never work and invoices will never be marked as cancelled.
            $count = 0;


            # count invoice items that match the invoice id
            $invoiceItems = InvoiceItem::where('invoiceid', $userInvoice->id)->get();
            $invoiceItemCount = count($invoiceItems);


            # Loop through invoice items that match the invoice id
            foreach ($invoiceItems as $invoiceItem) {

                # If we have addons then we can do a check to see if the relid on the invoice item matches either the main service ID or one of the addon ID's
                if (!empty($addons)) {

                    if ($invoiceItem->relid == $vars['serviceid'] || in_array($invoiceItem->relid, $addons)) {
                        $count++;
                        $related[] = $invoiceItem->id;
                    } else {
                        $unrelated[] = $invoiceItem->id;
                    }

                # Otherwise just check the main service ID if theres no addons.
                } else {

                    if ($invoiceItem->relid == $vars['serviceid']) {
                        $count++;
                        $related[] = $invoiceItem->id;
                    } else {
                        $unrelated[] = $invoiceItem->id;
                    }

                }

            }

            # Cancel the invoice
            try {

                Invoice::where('id', $userInvoice->id)->update([
                    'date_cancelled'    => date('Y-m-d H:i:s'),
                    'status'            => 'Cancelled',
                ]);

            } catch (\Exception $e) {
                logActivity("Unable to cancel invoice #{$userInvoice->id} for service #{$vars['serviceid']}. Reason: {$e->getMessage()}", $userInvoice->userid);
            }

            # If there are items on the invoice that arent related to the service or its addons, we need to split the invoices. This is messy, WHMCS don't provide an API function to split the invoice?!
            if ($invoiceItemCount != $count) {
                
                # Create new invoice, move invoice line item id's in the unrelated array to that invoice and generate the subtotal
                $command = 'CreateInvoice';

                $postData = [
                    'userid'        => $userInvoice->userid,
                    'status'        => 'Unpaid',
                    'sendinvoice'   => '0',
                    'paymentmethod' => $userInvoice->paymentmethod,
                    'taxrate'       => $userInvoice->taxrate,
                    'date'          => date('YYYY-MM-DD'),
                    'duedate'       => $userInvoice->duedate,
                ];

                $result         = localAPI($command, $postData);
                $newInvoiceID   = $result['invoiceid'];

                # Loop through invoice line items in the unrelated array and update them to the new invoice ID
                foreach($unrelated as $id) {

                    try {

                        InvoiceItem::where('id', $id)->update([
                            'invoiceid' => $newInvoiceID,
                        ]);
                    
                    } catch(\Exception $e) {
                        // Possibly log this error? No more todos. None. Nada.
                        logActivity("Failed to add new invoice items to invoice #{$newInvoiceID}. Reason: {$e->getMessage()}", 0);
                    }

                }

                # Update the subtotal on the invoice
                $newInvoice = Invoice::where('id', $newInvoiceID)->first();
                updateTheTotals($newInvoice, $unrelated);

                # Update the old invoice's subtotal (as it would be still what it was before we moved the items off, surprised whmcs hardcodes this to the database still!)
                updateTheTotals($userInvoice, $related);

                # Send out the Invoice Created email for the new invoice
                $command = 'SendEmail';
                
                $postData = array(
                    'messagename' => 'Invoice Created',
                    'id' => $newInvoiceID,
                );

                $results = localAPI($command, $postData);

            }

        }

    }

}

/**
 * updateTotals
 *
 * @param  mixed $invoice
 * @param  mixed $items
 * @return void
 */

function updateTheTotals($invoice, $items) {

    $subTotal = 0;
    $total    = 0;

    # Loop through each invoice item
    foreach ($items as $item) {

        # Add amount of each item to the sub total of the invoice
        $itemData = InvoiceItem::where('id', $item)->first();
        $subTotal += $itemData->amount;

    }
    
    # Correctly format it for WHMCS
    $subTotal = number_format($subTotal, 2, '.', '');

    # Get both tax values
    $tax    = $subTotal * $invoice->taxrate / 100;
    $tax2   = $subTotal * $invoice->taxrate2 / 100;

    # Get the final total of the invoice
    $total = ($subTotal + $tax + $tax2) - $invoice->credit;

    # Update the values on the invoice
    try {

        Invoice::where('id', $invoice->id)->update([
            'subTotal'  => $subTotal,
            'tax'       => $tax,
            'tax2'      => $tax2,
            'total'     => $total,
        ]);
    
    } catch(\Exception $e) {
        logActivity("Unable to update totals on invoice #{$invoice->id}. Reason: {$e->getMessage()}", $invoice->userid);
    }

}

/**
 * Register hook function call.
 *
 * @param string $hookPoint The hook point to call.
 * @param integer $priority The priority for the hook function.
 * @param function The function name to call or the anonymous function.
 *
 * @return This depends on the hook function point.
 */
add_hook('PreServiceEdit', 1, 'invoice_cancellation_helper_event');

# The downside of hooks, no ability to share variables between them.
add_hook('ServiceEdit', 1, function($vars) {
    
    if(isset($_SESSION['csh_notes']) && !empty($_SESSION['csh_notes'])) {

        $currentNotes = Service::where('id', $vars['serviceid'])->first()->notes;

        Service::where('id', $vars['serviceid'])->update([
            'notes' => $currentNotes . $_SESSION['csh_notes']
        ]);

        unset($_SESSION['csh_notes']);

    }

});
