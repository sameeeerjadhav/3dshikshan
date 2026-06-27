<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user'])) {
    $role = (string)($_SESSION['user']['role'] ?? '');
    header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'dashboard.php'));
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$colleges = [];
$courses = [];

$conn = getDbConnection();
if ($conn !== null) {
    $collegeResult = $conn->query('SELECT id, name, state, district FROM colleges ORDER BY name ASC');
    if ($collegeResult instanceof mysqli_result) {
        while ($row = $collegeResult->fetch_assoc()) {
            $colleges[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'state' => (string)$row['state'],
                'district' => (string)$row['district'],
            ];
        }
        $collegeResult->free();
    }

    $courseResult = $conn->query('SELECT id, course_name, description, duration, fees, required_details FROM courses ORDER BY course_name ASC');
    if ($courseResult instanceof mysqli_result) {
        while ($row = $courseResult->fetch_assoc()) {
            $courses[] = [
                'id' => (int)$row['id'],
                'course_name' => (string)$row['course_name'],
                'description' => (string)$row['description'],
                'duration' => (string)$row['duration'],
                'fees' => (string)$row['fees'],
                'required_details' => (string)$row['required_details'],
            ];
        }
        $courseResult->free();
    }

    $conn->close();
}

$razorpayEnabled = RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Student Sign Up - 3D Shikshan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/legal.css">
    <style>
        .signup-shell { max-width: 760px; margin: 0 auto; padding: 0 16px 22px; }
        .signup-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px 18px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .full { grid-column: 1 / -1; }
        .form-group select, .form-group textarea {
            width: 100%; padding: 11px 14px; background: var(--bg);
            border: 1px solid var(--border); border-radius: 10px; color: var(--text);
            font-family: 'DM Sans', sans-serif; font-size: .9rem; outline: none; transition: var(--transition);
        }
        .form-group textarea { min-height: 82px; resize: vertical; }
        .form-group select:focus, .form-group textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px #0b8a5e12; }
        .course-details {
            background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px;
            padding: 12px; margin-top: 8px; display: none;
        }
        .course-details p { margin: 0 0 6px; font-size: .82rem; color: var(--text-muted); }
        .course-details p:last-child { margin-bottom: 0; }
        .pay-note {
            margin-top: 12px; background: #fff7ed; border: 1px solid #fed7aa;
            color: #9a3412; font-size: .82rem; padding: 10px 12px; border-radius: 10px;
        }
        .signup-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-outline {
            border: 1px solid var(--border); background: var(--surface); color: var(--text);
            border-radius: 10px; padding: 11px 14px; font-weight: 700; text-decoration: none;
        }
        .success-box {
            background: #ecfdf3; border: 1px solid #86efac; color: #166534; border-radius: 10px;
            padding: 12px; font-size: .83rem; margin-bottom: 14px; display: none;
        }
        .popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(4, 12, 20, .45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            padding: 16px;
        }
        .popup-overlay.show { display: flex; }
        .popup-card {
            width: 100%;
            max-width: 560px;
            background: #08121a;
            border: 1px solid #1f3342;
            color: #f1f5f9;
            border-radius: 16px;
            box-shadow: 0 14px 40px rgba(0,0,0,.4);
            padding: 22px 24px 20px;
        }
        .popup-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: #e2e8f0;
        }
        .popup-message {
            font-size: .95rem;
            color: #dbeafe;
            line-height: 1.5;
            margin-bottom: 16px;
            white-space: pre-line;
        }
        .popup-actions {
            display: flex;
            justify-content: flex-end;
        }
        .popup-ok {
            min-width: 92px;
            border: 2px solid #67e8f9;
            background: transparent;
            color: #67e8f9;
            border-radius: 999px;
            padding: 8px 14px;
            font-weight: 700;
            cursor: pointer;
            transition: .2s;
        }
        .popup-ok:hover {
            background: #67e8f920;
        }
        .loader-inline { font-size: .82rem; color: var(--text-muted); margin-top: 10px; display: none; }
        @media (max-width: 640px) { .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="signup-shell">
    <div class="top-bar">
        <i class="fa-solid fa-user-plus top-icon"></i>
        <h2>Student Registration</h2>
    </div>

    <div class="signup-card">
        <div class="login-header" style="padding-top:0;">
            <img src="assets/logo.png" alt="3D Shikshan" style="height: 100px; object-fit: contain; margin-bottom: 15px;">
            <h2>Create Student Account</h2>
        </div>

        <div class="success-box" id="signupSuccess"></div>

        <div class="popup-overlay" id="popupOverlay" role="dialog" aria-modal="true" aria-labelledby="popupTitle">
            <div class="popup-card">
                <div class="popup-title" id="popupTitle">3D Shikshan</div>
                <div class="popup-message" id="popupMessage"></div>
                <div class="popup-actions">
                    <button type="button" class="popup-ok" id="popupOkBtn">OK</button>
                </div>
            </div>
        </div>

        <form id="signupForm" autocomplete="off" novalidate>
            <div class="grid-2">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                <div class="form-group">
                    <label for="mobile_no">Mobile No</label>
                    <input type="text" id="mobile_no" name="mobile_no" maxlength="15" required>
                </div>
                <div class="form-group full">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="state">State</label>
                    <select id="state" name="state" required>
                        <option value="">Select State</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="district">District</label>
                    <select id="district" name="district" required>
                        <option value="">Select District</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="college_id">College</label>
                    <select id="college_id" name="college_id" required>
                        <option value="">Select College</option>
                    </select>
                </div>

                <input type="hidden" id="course_id" name="course_id" value="">

                <div class="form-group full">
                    <label for="amount_to_pay">Amount to Pay (₹)</label>
                    <input type="number" id="amount_to_pay" name="amount_to_pay" min="1000" step="0.01" placeholder="Enter amount" required>
                </div>
            </div>



            <?php if ($razorpayEnabled): ?>
                <?php require __DIR__ . '/includes/payment_consent.php'; ?>
            <?php endif; ?>

            <div class="loader-inline" id="signupLoader"><i class="fa-solid fa-spinner fa-spin"></i> Processing payment...</div>

            <div class="signup-actions">
                <button type="submit" class="btn-login" id="payRegisterBtn" <?php echo $razorpayEnabled ? '' : 'disabled'; ?>>Pay & Sign Up</button>
                <a href="index.php?login=1" class="btn-outline">Back to Login</a>
            </div>
            <?php if (!$razorpayEnabled): ?>
                <div class="pay-note" style="background:#fff1f2;border-color:#fecdd3;color:#9f1239;margin-top:10px;">
                    Razorpay is not configured. Add keys in config.php to enable signup payment.
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="page-footer">
        &copy; 2026 3D Shikshan
        <div class="payment-legal-footer">
            <a href="terms.php">Terms</a> ·
            <a href="privacy.php">Privacy</a> ·
            <a href="refund.php">Refunds</a>
        </div>
    </div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
const colleges = <?php echo json_encode($colleges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const courses = <?php echo json_encode($courses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const razorpayEnabled = <?php echo $razorpayEnabled ? 'true' : 'false'; ?>;

const stateEl = document.getElementById('state');
const districtEl = document.getElementById('district');
const collegeEl = document.getElementById('college_id');
const courseEl = document.getElementById('course_id');
const amountToPayEl = document.getElementById('amount_to_pay');
const loaderEl = document.getElementById('signupLoader');
const successEl = document.getElementById('signupSuccess');
const formEl = document.getElementById('signupForm');
const payBtn = document.getElementById('payRegisterBtn');
const paymentTermsEl = document.getElementById('paymentTermsAccept');
const popupOverlayEl = document.getElementById('popupOverlay');
const popupMessageEl = document.getElementById('popupMessage');
const popupOkBtnEl = document.getElementById('popupOkBtn');

function showPopup(message) {
    popupMessageEl.textContent = message;
    popupOverlayEl.classList.add('show');
}

function closePopup() {
    popupOverlayEl.classList.remove('show');
}

popupOkBtnEl.addEventListener('click', closePopup);
popupOverlayEl.addEventListener('click', function (event) {
    if (event.target === popupOverlayEl) {
        closePopup();
    }
});

function uniqueSorted(values) {
    return [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b));
}

function fillSelect(el, values, placeholder) {
    el.innerHTML = `<option value="">${placeholder}</option>`;
    values.forEach(v => {
        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = v;
        el.appendChild(opt);
    });
}

function fillCollegeSelect(filteredColleges) {
    collegeEl.innerHTML = '<option value="">Select College</option>';
    filteredColleges.forEach(c => {
        const opt = document.createElement('option');
        opt.value = String(c.id);
        opt.textContent = c.name;
        collegeEl.appendChild(opt);
    });
}

function updateStates() {
    fillSelect(stateEl, uniqueSorted(colleges.map(c => c.state)), 'Select State');
}

function updateDistricts() {
    const selectedState = stateEl.value;
    const districts = uniqueSorted(colleges.filter(c => c.state === selectedState).map(c => c.district));
    fillSelect(districtEl, districts, 'Select District');
    fillCollegeSelect([]);
}

function updateColleges() {
    const selectedState = stateEl.value;
    const selectedDistrict = districtEl.value;
    const filtered = colleges.filter(c =>
        c.state === selectedState && c.district === selectedDistrict
    );
    fillCollegeSelect(filtered);
}

function updateCourses() {
    if (courses.length > 0) {
        courseEl.value = String(courses[0].id);
        const maxPayable = getCourseFeeCap(courses[0]);
        amountToPayEl.min = '1000';
        amountToPayEl.max = String(maxPayable);
        if (!amountToPayEl.value || Number(amountToPayEl.value) > maxPayable || Number(amountToPayEl.value) < 1000) {
            amountToPayEl.value = maxPayable.toFixed(2);
        }
    }
}

function parseCourseFees(feesText) {
    const cleaned = String(feesText).replace(/[^0-9.]/g, '');
    const parsed = Number(cleaned);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function getSelectedCourse() {
    const id = Number(courseEl.value);
    return courses.find(c => Number(c.id) === id) || null;
}

function computePayable(selectedCourse) {
    if (!selectedCourse) return 1000;
    return Math.max(1000, parseCourseFees(selectedCourse.fees));
}

function getCourseFeeCap(selectedCourse) {
    if (!selectedCourse) return 0;
    return parseCourseFees(selectedCourse.fees);
}



function validateForm() {
    const requiredIds = ['first_name','last_name','mobile_no','email','state','district','college_id','course_id'];
    for (const id of requiredIds) {
        const el = document.getElementById(id);
        if (!String(el.value || '').trim()) {
            el.focus();
            return `Please fill ${id.replace('_', ' ')}.`;
        }
    }

    const mobile = document.getElementById('mobile_no').value.trim();
    if (!/^\d{10,15}$/.test(mobile)) {
        document.getElementById('mobile_no').focus();
        return 'Enter valid mobile number (10-15 digits).';
    }

    const selectedCourse = courses.length > 0 ? courses[0] : null;
    if (!selectedCourse) {
        return 'No courses available for registration.';
    }

    const maxPayable = getCourseFeeCap(selectedCourse);
    const enteredAmount = Number(amountToPayEl.value);
    if (!Number.isFinite(enteredAmount) || enteredAmount <= 0) {
        amountToPayEl.focus();
        return 'Enter valid amount to pay.';
    }
    if (maxPayable < 1000) {
        return 'Selected course fee is below minimum payable ₹1000. Contact admin.';
    }
    if (enteredAmount < 1000 || enteredAmount > maxPayable) {
        amountToPayEl.focus();
        return `Amount must be between ₹1000 and ₹${maxPayable.toFixed(2)}.`;
    }

    return null;
}

stateEl.addEventListener('change', updateDistricts);
districtEl.addEventListener('change', updateColleges);
courseEl.addEventListener('change', function() {});

updateStates();
updateCourses();

formEl.addEventListener('submit', async function (event) {
    event.preventDefault();

    if (!razorpayEnabled) {
        showPopup('Razorpay is not configured by admin.');
        return;
    }

    const validationError = validateForm();
    if (validationError) {
        showPopup(validationError);
        return;
    }

    if (paymentTermsEl && !paymentTermsEl.checked) {
        showPopup('Please accept the Terms & Conditions, Privacy Policy, Refund Policy, and Razorpay terms to continue.');
        return;
    }

    payBtn.disabled = true;
    loaderEl.style.display = 'block';

    const formData = new FormData(formEl);
    const payload = Object.fromEntries(formData.entries());

    try {
        const orderRes = await fetch('student_create_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                course_id: payload.course_id,
                email: payload.email,
                amount_rupees: payload.amount_to_pay,
                terms_accepted: true
            })
        });
        const orderData = await orderRes.json();
        if (!orderData.ok) {
            throw new Error(orderData.error || 'Unable to create payment order.');
        }

        const options = {
            key: orderData.key,
            amount: orderData.amount,
            currency: orderData.currency,
            name: orderData.company || '3D_Shikshan',
            description: 'Student Registration Fee',
            order_id: orderData.order_id,
            prefill: {
                name: `${payload.first_name} ${payload.last_name}`.trim(),
                email: payload.email,
                contact: payload.mobile_no
            },
            theme: { color: '#0b8a5e' },
            notes: {
                policy: 'Terms, Privacy & Refund accepted on signup'
            },
            handler: async function (response) {
                try {
                    const completeRes = await fetch('student_complete_registration.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            ...payload,
                            razorpay_order_id: response.razorpay_order_id,
                            razorpay_payment_id: response.razorpay_payment_id,
                            razorpay_signature: response.razorpay_signature
                        })
                    });

                    const completeData = await completeRes.json();
                    if (!completeData.ok) {
                        throw new Error(completeData.error || 'Registration failed after payment.');
                    }

                    successEl.style.display = 'block';
                    successEl.innerHTML = `Registration successful.<br>Email/Login ID: <strong>${completeData.login_id}</strong><br>Auto-generated Password: <strong>${completeData.generated_password}</strong><br>Please save this password and login.`;
                    formEl.reset();
                    renderCourseDetails();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } catch (error) {
                    showPopup(error.message || 'Registration failed.');
                } finally {
                    loaderEl.style.display = 'none';
                    payBtn.disabled = false;
                }
            },
            modal: {
                ondismiss: function () {
                    loaderEl.style.display = 'none';
                    payBtn.disabled = false;
                }
            }
        };

        const razorpay = new Razorpay(options);
        razorpay.open();
    } catch (error) {
        loaderEl.style.display = 'none';
        payBtn.disabled = false;
        showPopup(error.message || 'Could not initiate payment.');
    }
});
</script>
</body>
</html>
