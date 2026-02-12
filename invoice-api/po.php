<?php
// Load Dompdf
require_once __DIR__ . '/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$custom_params = [];
// Custom logic to parse query string using '\' as separator
if (isset($_SERVER['QUERY_STRING']) && strpos($_SERVER['QUERY_STRING'], '\\') !== false) {
    $full_raw_query = $_SERVER['QUERY_STRING'];
    
    $segments = explode('\\', $full_raw_query);
    foreach ($segments as $segment) {
        $parts = explode('=', $segment, 2);
        if (count($parts) === 2) {
            // Key is still trimmed for reliable matching (e.g., " Key=" )
            $key = trim($parts[0]); 
            // VALUE IS NOT TRIMMED, as per user request
            $value = $parts[1]; 
            $custom_params[$key] = $value;
        }
    }
}

/**
 * Get parameter value, decode URL encoding, and escape for HTML output.
 * General trim() has been REMOVED to preserve spaces, including those in addresses.
 */
function get_param_value($key) {
    global $custom_params;
    
    $raw_value = '';
    
    if (isset($custom_params[$key])) {
        // Value is used AS IS (no trim)
        $raw_value = $custom_params[$key]; 
    }
    else if (isset($_GET[$key])) {
        // Value is used AS IS (no trim)
        $raw_value = $_GET[$key];
    }
    
    // Decode URL encoding (like %23 to #). This is necessary.
    $decoded_value = urldecode($raw_value);
    
    // Escape HTML special characters for safe output. This is necessary.
    return htmlentities($decoded_value, ENT_QUOTES, 'UTF-8');
}

// Document Type set to Purchase Order format
$document_type = 'PURCHASE ORDER'; 
$document_type = ucwords(strtolower($document_type)); // Sets it to "Purchase Order"

$document_prefix = 'PO'; 

/**
 * This function handles trimming only for the array elements created by the '~~' separator (as requested).
 */
function parse_items($param) {
    $raw = get_param_value($param); 
    // Trimming is applied only to the elements after splitting by '~~' 
    $items = array_map('trim', explode('~~', $raw));
    return array_filter($items, fn($v) => $v !== '');
}

function clean_narration($narration, $logo_url) {
    // trim() is necessary here to clean up the narration string and remove leading/trailing parentheses
    $cleaned = str_replace($logo_url, '', trim($narration));
    return trim($cleaned, '() ');
}

function has_bank_details($bank, $ac, $ifsc) {
    return !empty($bank) || !empty($ac) || !empty($ifsc);
}

function numberToWordsINR($number, $currency = 'INR', &$is_round_off_applied = false) {
    
    $original_number = floatval($number);
    $currency_upper = strtoupper($currency);

    if ($currency_upper === 'INR') {
        $number = round($original_number); 
        $decimal_part = 0; 
        $is_round_off_applied = true; 
        $no = $number;
    } else {
        $number = floor($original_number * 100) / 100;
        $no = floor($number);
        $decimal_part = round(($original_number - $no) * 100);
        $is_round_off_applied = false;
    }
    
    if ($currency_upper === 'INR') {
        $main_unit = 'RUPEES';
        $decimal_unit = 'PAISE'; 
    } else {
        $main_unit = $currency_upper;
        if (in_array($currency_upper, ['USD', 'CAD', 'EUR', 'AUD', 'SGD'])) {
            $decimal_unit = 'CENTS';
        } elseif ($currency_upper === 'AED') {
            $decimal_unit = 'FILS';
        } else {
            $decimal_unit = 'DECIMAL';
        }
    }
    
    $digits_1 = array(
        0 => '', 1 => 'ONE', 2 => 'TWO', 3 => 'THREE', 4 => 'FOUR', 5 => 'FIVE', 6 => 'SIX', 7 => 'SEVEN', 8 => 'EIGHT', 9 => 'NINE', 
        10 => 'TEN', 11 => 'ELEVEN', 12 => 'TWELVE', 13 => 'THIRTEEN', 14 => 'FOURTEEN', 15 => 'FIFTEEN', 16 => 'SIXTEEN', 17 => 'SEVENTEEN', 18 => 'EIGHTEEN', 19 => 'NINETEEN'
    );
    $digits_2 = array(
        0 => '', 1 => '', 2 => 'TWENTY', 3 => 'THIRTY', 4 => 'FORTY', 5 => 'FIFTY', 6 => 'SIXTY', 7 => 'SEVENTY', 8 => 'EIGHTY', 9 => 'NINETY'
    );
    
    $convert_triple = function($n) use ($digits_1, $digits_2) {
        $out = '';
        if ($n >= 100) {
            $out .= $digits_1[floor($n / 100)] . ' HUNDRED ';
            $n %= 100;
        }
        if ($n > 0) {
            if ($n < 20) {
                $out .= $digits_1[$n];
            } else {
                $out .= $digits_2[floor($n / 10)] . ' ' . $digits_1[$n % 10];
            }
        }
        return trim($out);
    };

    $groups = array(
        'CRORE' => 10000000, 
        'LAKH' => 100000, 
        'THOUSAND' => 1000, 
    );
    
    $group_units = array();
    $remainder = $no;

    foreach ($groups as $label => $divisor) {
        if ($remainder >= $divisor) {
            $group_units[$label] = floor($remainder / $divisor);
            $remainder %= $divisor;
        }
    }

    $word_rupees = '';
    foreach ($group_units as $label => $val) {
        $word_rupees .= $convert_triple($val) . ' ' . $label . ' ';
    }
    
    if ($remainder > 0) {
        $word_rupees .= $convert_triple($remainder);
    }
    
    $final_string = trim($word_rupees);
    
    if (!empty($final_string)) {
        $final_string .= ' ' . $main_unit;
    } else {
        $final_string = "ZERO " . $main_unit;
    }
    
    if ($currency_upper !== 'INR' && $decimal_part > 0) {
        $decimal_words = $convert_triple($decimal_part);
        $final_string .= " AND " . trim($decimal_words) . " " . $decimal_unit;
    }

    $title_case_string = ucwords(strtolower($final_string));
    
    return trim($title_case_string) . " Only";
}

// Fetch Parameters
$company_name       = get_param_value('Company_Name');
$company_address = get_param_value('Company_Address');
$company_cin         = get_param_value('Conpany_CIN');
$company_pan         = get_param_value('Company_PAN');
$company_gstin       = get_param_value('Company_TAX_ID');
$company_email       = get_param_value('Company_Email');
$company_bank        = get_param_value('Company_Bank');
$company_bank_ac = get_param_value('Company_Bank_AC');
$company_ifsc        = get_param_value('Company_IFSC');

$invoice_no          = get_param_value('Invoice_No');
$create_date     = get_param_value('Create_Date');
$customer_name   = get_param_value('Customer_Name');
$customer_tax    = get_param_value('Customer_TAX_ID');
$billing_address = get_param_value('Billing_Address');
$shipping_address= get_param_value('Final_Shipping_Address');
$contact_person  = get_param_value('Contact_Person');
$customer_phone  = get_param_value('Customer_Phone');
$currency        = get_param_value('Currency') ?: 'INR';
$payment_terms   = get_param_value('Payment_Terms');
$validity        = get_param_value('Validity');
$customer_location = get_param_value('Customer_Location'); 
$logo_url        = get_param_value('Company_logo_Url');
$watermark_url = ''; 
$submitted_by      = get_param_value('Submitted_by');
$submitted_by_phone = get_param_value('Submitted_by_phone');
$po_number         = get_param_value('PO_Number'); 

$tax_label = (strtolower($customer_location) === 'domestic') ? 'GSTIN' : 'TAX ID';

// Total Amounts
$total_item_net_raw          = get_param_value('Total_Item_Net_Price');
$total_item_tax_raw          = get_param_value('Total_Item_Tax_Amount');
$total_item_final_raw = get_param_value('Total_Item_Final_Amount');

$total_item_net_clean        = floatval(str_replace(',', '', $total_item_net_raw));
$total_item_tax_clean        = floatval(str_replace(',', '', $total_item_tax_raw));
$total_item_final_clean = floatval(str_replace(',', '', $total_item_final_raw)); 

$grand_total_raw   = get_param_value('Invoice_Grand_Total');
$grand_total_clean = str_replace(',', '', $grand_total_raw); 
$original_grand_total_float = floatval($grand_total_clean);

$is_round_off_applied = false;
$amount_in_words = numberToWordsINR($grand_total_clean, $currency, $is_round_off_applied);

$rounded_grand_total = $original_grand_total_float; 
$round_off_difference = 0.00;
$round_off_display = ''; 

if (strtoupper($currency) === 'INR') {
    $rounded_grand_total = round($original_grand_total_float);
    $round_off_difference = $rounded_grand_total - $original_grand_total_float;
    $round_off_display = ($round_off_difference >= 0) 
        ? '+' . number_format($round_off_difference, 2)
        : number_format($round_off_difference, 2); 
}

// Freight Details
$freight_type        = get_param_value('Freight_Type');
$freight_amount_raw  = get_param_value('Freight_Amount');
$freight_tax         = get_param_value('Freight_Tax');
$freight_tax_amt_raw = get_param_value('Freight_Tax_Amount');
$freight_final_raw   = get_param_value('Freight_Final_Amount');

$freight_amount_clean  = floatval(str_replace(',', '', $freight_amount_raw));
$freight_tax_amt_clean = floatval(str_replace(',', '', $freight_tax_amt_raw));
$freight_final_clean   = floatval(str_replace(',', '', $freight_final_raw));

$is_custom_freight = (strtolower($freight_type) === 'custom amount');

// Item Details
$all_item_names    = parse_items('All_Item_Names');
$all_uoms          = parse_items('All_UOMs');
$all_quantities    = parse_items('All_Quantities');
$all_unit_prices  = parse_items('All_UnitPrices'); 
$all_discount_pct = parse_items('All_Discount_Percent');
$all_discount_amt = parse_items('All_Discount_Amount');
$all_net_prices    = parse_items('All_Net_Prices'); 
$all_tax_pct      = parse_items('All_Tax_Percent');
$all_tax_amt      = parse_items('All_Tax_Amount');
$all_final_amt    = parse_items('All_Final_Amounts');
$all_lead_times    = parse_items('All_Lead_Times');
$all_narrations_raw = parse_items('All_Narrations');
$all_narrations = array_map(fn($n) => clean_narration($n, $logo_url), $all_narrations_raw);

$show_bank_details = has_bank_details($company_bank, $company_bank_ac, $company_ifsc);

$accent_color = '#000000'; 
$main_color_text = '#222'; 
$line_color = '#333'; 
$grand_total_bg = '#f0f0f0'; 

$submitted_by_display = $submitted_by;
if (!empty($submitted_by_phone)) {
    // Ensuring '+' sign is added for submitted_by phone
    $submitted_by_display .= ' | +' . $submitted_by_phone;
}

// -----------------------------------------------------------------------------
// HTML & CSS GENERATION
// -----------------------------------------------------------------------------

$html = '<html><head><style>
@import url("https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap");

body { font-family: "Roboto", Arial, sans-serif; font-size:11px; margin:0; padding:30px; color:#333; }
h1,h3 { margin:0; padding:0; font-weight:700; }
h1 { font-size:24px; color:'.$main_color_text.'; text-align: center; margin-bottom: 2px; }
h3 { font-size:13px; color:'.$main_color_text.'; font-weight: 700; }
p { margin: 2px 0; }
.label { font-weight: 500; color: #555; }
.clearfix::after { content: ""; clear: both; display: table; }

.header-container { 
    margin-bottom: 5px; 
    padding-bottom: 0px; 
    overflow: hidden;
}
.header-row {
    width: 100%;
    display: table;
    table-layout: fixed;
}
.header-cell {
    display: table-cell;
    vertical-align: top; 
    padding: 0 5px;
}

.logo-cell {
    width: 30%;
    text-align: left;
    height: 70px;
} 
.logo-container { 
    height: 70px; 
    width: 100%;
} 
.logo { 
    max-height: 100%; 
    width: auto; 
}

.name-address-cell { 
    width: 50%; 
    text-align: center; 
} 
.company-address-info { 
    font-size: 14px; 
    line-height: 1.3; 
    color: #555; 
    text-align: center; 
} 
h1 {
    font-size: 24px; 
    color: '.$main_color_text.'; 
    text-align: center; 
    margin-bottom: 2px;
}

.company-tax-cell { 
    width: 25%; 
    text-align: right; 
    font-size: 11px; 
    line-height: 1.4; 
    color: #555; 
}
.company-tax-cell p {
    margin: 1px 0;
}


.info-section { 
    margin: 0 0 15px 0; 
    border-bottom: 2px solid '.$line_color.'; 
    border-top: 2px solid '.$line_color.'; 
    overflow: hidden; 
}
.info-section table { width: 100%; border-collapse: collapse; }
.info-section td { 
    border: none; 
    padding: 8px 0; 
    vertical-align: top; 
    width: 33.33%; 
    font-size: 12px;
}
.info-section td:nth-child(1) { border-right: 1px dashed #eee; padding-right: 10px; }
.info-section td:nth-child(2) { border-right: 1px dashed #eee; padding-right: 10px; padding-left: 10px; }
.info-section td:nth-child(3) { padding-left: 10px; }

.info-title { 
    font-size: 13px; 
    font-weight: 700; 
    color: '.$main_color_text.'; 
    margin-bottom: 4px; 
    text-transform: uppercase; 
}
.info-content strong { font-size: 13px; color: #222; }

table.items { 
    border-collapse: collapse; 
    width: 100%; 
    margin-bottom: 10px; 
}
.items th, .items td { 
    border: 1px solid #ddd; 
    padding: 4px 3px; 
    font-size: 10.5px; 
}
.items th { 
    background: #f0f0f0; 
    color: #333; 
    text-align: center; 
    font-weight: 600; 
    font-size: 10.5px; 
}
.items tr:nth-child(even) { background: #fcfcfc; }
.items td { vertical-align: middle; }

.col-sno { width: 3%; }
.col-desc { width: 30%; } 
.col-qty { width: 5%; } 
.col-uom { width: 5%; }
.col-pct { width: 5%; } 
.col-price { width: 10%; } 
.col-final { 
    width: 12%; 
    font-weight: 700; 
    color: '.$main_color_text.'; 
}
.col-lead { width: 8%; }

.right { text-align: right; }
.center { text-align: center; }

.totals-row td { 
    font-weight: bold; 
    background: #f0f0f0; 
    border-top: 2px solid #ccc; 
    color: #222;
}

.summary-container { 
    margin-top: 5px; 
    width: 100%; 
    overflow: hidden; 
}
.freight-details {
    width: 100%; 
    float: none; 
    margin-top: 5px; 
    padding: 8px;
    font-size: 11px;
    line-height: 1.4;
    border: 1px solid #ccc;
    background: #fcfcfc;
    text-align: left; 
}

.amount-in-words-full {
    clear: both;
    width: 100%; 
    float: none; 
    font-size: 11px;
    margin-top: 8px; 
    line-height: 1.4;
    padding: 5px 0; 
    font-weight: 700;
}
.amount-in-words-full strong {
    font-size: 12px;
    color: '.$main_color_text.'; 
    font-weight: 700;
    display: inline; 
    margin-left: 5px;
    text-transform: capitalize; 
}

.footer-box { 
    margin-top: 15px; 
    font-size: 11px;
    border-top: 1px solid #ccc; 
    padding-top: 15px;
}

.bank-details-container { 
    width: 49%; 
    float: left;
    padding-right: 1%;
}
.bank-details { 
    width: 100%; 
    padding: 10px; 
    border: 1px solid #ccc; 
    border-radius: 4px;
    box-sizing: border-box; 
    margin-bottom: 5px; 
}

.prepared-by {
    clear: left;
    width: 100%;
    margin-top: 10px;
    padding: 5px 0;
    font-size: 12px;
}
.prepared-by span.label {
    font-size: 12px;
}
.prepared-by strong {
    font-size: 13px;
    display: block;
    margin-top: 3px;
    padding-top: 5px;
    border-top: 1px solid #000;
    width: 50%; 
}

.totals-table-footer { 
    width: 49%; 
    float: right; 
    border: 1px solid #ccc; 
    margin-top: 0;
}
.totals-table-footer td { 
    border: none; 
    padding: 5px 10px; 
    font-weight: 500; 
    font-size: 12px;
    text-align: right; 
}
.totals-table-footer td:nth-child(1) {
    text-align: left; 
    padding-right: 5px;
}
.totals-table-footer .grand-total-row td { 
    font-size: 14px; 
    font-weight: 700; 
    background: '.$grand_total_bg.'; 
    color: '.$main_color_text.'; 
    border-top: 2px solid #ccc; 
}

.full-width-disclaimer {
    clear: both;
    margin-top: 15px; 
    padding-top: 10px;
    border-top: 1px solid #ccc;
    text-align: center;
    color: #555;
    font-style: italic;
    font-size: 11px;
}
</style></head><body>

<div>

<div class="header-container">
    <div class="header-row">
        
        <div class="header-cell logo-cell">
            <div class="logo-container">
                <img src="'.$logo_url.'" class="logo">
            </div>
        </div>

        <div class="header-cell name-address-cell">
            <h1>'.$company_name.'</h1>
            <div class="company-address-info">'.$company_address.'</div>
        </div>

        <div class="header-cell company-tax-cell">
            '.(!empty($company_cin) ? '<p><span class="label">CIN:</span> '.$company_cin.'</p>' : '').'
            '.(!empty($company_pan) ? '<p><span class="label">PAN:</span> '.$company_pan.'</p>' : '').'
            '.(!empty($company_gstin) ? '<p><span class="label">GSTIN:</span> '.$company_gstin.'</p>' : '').'
            '.(!empty($company_email) ? '<p><span class="label">Email:</span> '.$company_email.'</p>' : '').'
        </div>
    </div>
</div>

<div class="info-section">
<table>
<tr>
<td>
<div class="info-title">Seller / Vendor Name</div>
<div class="info-content">
<strong>'.$customer_name.'</strong><br>
'.$billing_address.'<br>
<span class="label">'.$tax_label.':</span> '.$customer_tax.'
</div>
</td>

<td>
<div class="info-title">Ship To Address</div>
<div class="info-content">
<strong>'.$company_name.'</strong><br>
'.$shipping_address.'
</div>
</td>

<td>

<div class="info-content">
<span class="label">Document Type:</span> <strong>'.$document_type.'</strong><br>
<span class="label">Document No.:</span> <strong>'.$invoice_no.'</strong><br>
'.(!empty($po_number) ? '<span class="label">Reference:</span> <strong>'.$po_number.'</strong><br>' : '').'
<span class="label">Issue Date:</span> <strong>'.$create_date.'</strong><br>
<span class="label">Payment Terms:</span> '.$payment_terms.'<br>
<span class="label">Validity:</span> '.$validity.'
</div>
</td>

</tr>
</table>
</div>

<table class="items">
<thead>
<tr>
<th class="col-sno">#</th>
<th class="col-desc">Description</th>
<th class="col-qty">Qty</th>
<th class="col-uom">UOM</th>
<th class="col-price right">Unit Price</th>
<th class="col-pct right">Disc.(%)</th> 
<th class="col-price right">Net Price</th>
<th class="col-pct right">Tax(%)</th> 
<th class="col-price right">Tax Amt.</th>
<th class="col-final right">Final Amt. ('.$currency.')</th>
<th class="col-lead center">Delivery Date</th>
</tr>
</thead>
<tbody>';

$item_count = count($all_item_names);
for($i=0;$i<$item_count;$i++){
    
    $discount_pct_display = $all_discount_pct[$i] ?? '0';

$html .= '<tr>
<td class="center">'.($i+1).'</td>
<td class="col-desc">'.$all_item_names[$i];
if(!empty($all_narrations[$i])){ $html .= '<br><small style="color:#666;">( '.$all_narrations[$i].' )</small>'; }
$html .= '</td>
<td class="right">'.$all_quantities[$i].'</td>
<td class="center">'.$all_uoms[$i].'</td>
<td class="right">'.$all_unit_prices[$i].'</td>
<td class="right">'.$discount_pct_display.'</td> 
<td class="right">'.$all_net_prices[$i].'</td>
<td class="right">'.$all_tax_pct[$i].'</td>
<td class="right">'.$all_tax_amt[$i].'</td>
<td class="right col-final">'.$all_final_amt[$i].'</td>
<td class="center">'.$all_lead_times[$i].'</td>
</tr>';
}

$html .= '<tr class="totals-row">
<td colspan="6" class="right">Item Totals</td> 
<td class="right col-price">'.number_format($total_item_net_clean, 2).'</td>
<td class="col-pct"></td>
<td class="right col-price">'.number_format($total_item_tax_clean, 2).'</td>
<td class="right col-final">'.number_format($total_item_final_clean, 2).'</td>
<td class="col-lead"></td>
</tr>';

$html .= '</tbody></table>

<div class="clearfix summary-container">';

if(!empty($freight_type) && strtolower($freight_type) !== '0') {
    $html .= '<div class="freight-details">';
    
    if($is_custom_freight){
        $html .= '<span class="freight-item"><span class="label-title">AMOUNT:</span> <strong>'.number_format($freight_amount_clean, 2).' '.$currency.'</strong></span>';
        
        if($freight_tax_amt_clean > 0){
            $html .= '<span class="freight-item"><span class="label-title">TAX ('.$freight_tax.'%):</span> <strong>'.number_format($freight_tax_amt_clean, 2).' '.$currency.'</strong></span>';
        }
        
        $html .= '<span class="freight-item"><span class="label-title">TOTAL FREIGHT:</span> <strong>'.number_format($freight_final_clean, 2).' '.$currency.'</strong></span>';
    } 
    else {
          $html .= '<span class="freight-item"><span class="label-title">FREIGHT TYPE:</span> <strong>'.$freight_type.'</strong></span>';
    }
    
    $html .= '</div>';
}

$html .= '</div>'; 

$html .= '<div class="clearfix"></div>'; 

$html .= '<div class="amount-in-words-full">
    <span class="label-words">Total Amount in Words:</span>
    <strong>'.$amount_in_words.'</strong>
</div>';

$html .= '<div class="clearfix footer-box">';

$html .= '<div class="bank-details-container">';
    if($show_bank_details){
        $html .= '<div class="bank-details">
            <h3>Our Bank Details</h3>
            <p><span class="label">Bank:</span> '.$company_bank.'</p>
            <p><span class="label">Account No:</span> '.$company_bank_ac.'</p>
            <p><span class="label">IFSC:</span> '.$company_ifsc.'</p>
        </div>';
    }
    
    if(!empty($submitted_by_display)){
        $html .= '<div class="prepared-by">
            <span class="label">Issued By:</span>
            <strong>'.$submitted_by_display.'</strong>
        </div>';
    }
$html .= '</div>'; 

$html .= '<table class="totals-table-footer">';

$html .= '<tr><td class="label">Subtotal:</td><td>'.number_format($total_item_final_clean, 2).'</td></tr>';

if($is_custom_freight){
    $html .= '<tr><td class="label">Total Freight:</td><td>'.number_format($freight_final_clean, 2).'</td></tr>';
}

if (strtoupper($currency) === 'INR' && abs($round_off_difference) > 0.005) {
    $html .= '<tr><td class="label" style="font-weight:400;">Document Total (Pre-Round):</td><td style="font-weight:400;">'.number_format($original_grand_total_float, 2).'</td></tr>';
    
    $html .= '<tr class="round-off-row"><td class="label">Round Off:</td><td>'.$round_off_display.'</td></tr>';
}

$html .= '<tr class="grand-total-row"><td class="label">GRAND TOTAL ('.$currency.') :</td><td>';
$html .= number_format($rounded_grand_total, 2); 
$html .= '</td></tr>';
$html .= '</table>';


$html .= '<div class="full-width-disclaimer">';
$html .= 'This is a system-generated '.$document_type.' and is subject to change. Please confirm before executing the order.';
$html .= '</div>';

$html .= '</div>'; 

$html .= '</div></body></html>';

// -----------------------------------------------------------------------------
// DOMPDF Configuration
// -----------------------------------------------------------------------------

$options = new Options();
$options->set('isRemoteEnabled', TRUE); 
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A3', 'portrait');
$dompdf->render();

// Final file name format: Document_DOCument_Number.pdf
$dompdf->stream("Document_".$invoice_no.".pdf", ["Attachment" => false]);
exit;
?>