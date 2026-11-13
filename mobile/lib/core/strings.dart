/// UI language. The legacy citizen portal is bilingual: Lao on top, English
/// underneath. Lao is the default because the audience is Lao-speaking.
enum AppLang { lo, en }

class Strings {
  const Strings._();

  static AppLang current = AppLang.lo;

  static void setLanguage(AppLang lang) => current = lang;

  static bool get isLao => current == AppLang.lo;

  /// Look up a label by key. Lao first, English as the fallback.
  static String t(String key) {
    final lo = _lo[key];
    final en = _en[key];
    if (isLao) return lo ?? en ?? key;
    return en ?? lo ?? key;
  }

  static const Map<String, String> _lo = <String, String>{
    'appTitle': 'ຊຳລະຄ່າທີ່ດິນ',
    'appSubtitle': 'ການບໍລິການທີ່ດິນສາທາລະນະ',
    'signIn': 'ເຂົ້າສູ່ລະບົບ',
    'signOut': 'ອອກຈາກລະບົບ',
    'phone': 'ເບີໂທ',
    'createAccount': 'ສ້າງບັນຊີໃໝ່',
    'findParcel': 'ຄົ້ນຫາຕອນດິນ',
    'myParcels': 'ຕອນດິນຂອງຂ້ອຍ',
    'myPayments': 'ການຊຳລະຂອງຂ້ອຍ',
    'myAccount': 'ບັນຊີຂອງຂ້ອຍ',
    'about': 'ກ່ຽວກັບ',
    'parcelNo': 'ເລກຕອນດິນ',
    'mapSheet': 'ເລກແຜນທີ່',
    'province': 'ແຂວງ',
    'enterParcelIdentity':
        'ກະລຸນາເລືອກແຂວງ, ເມືອງ, ບ້ານ ແລະ ປ້ອນເລກແຜນທີ່ ແລະ ເລກຕອນດິນ',
    'parcelNotFound':
        'ບໍ່ພົບຕອນດິນ — ກະລຸນາກວດເບິ່ງ ເລກແຜນທີ່ ແລະ ເລກຕອນດິນ ໃຫ້ກົງກັບໃບຕາດິນ',
    'ambiguousParcel':
        'ຕອນດິນເລກນີ້ ມີຫຼາຍກວ່າໜຶ່ງຕອນ — ກະລຸນາຕິດຕໍ່ຫ້ອງການທີ່ດິນ',
    'district': 'ເມືອງ',
    'village': 'ບ້ານ',
    'area': 'ເນື້ອທີ່',
    'zone': 'ເຂດ',
    'owner': 'ເຈົ້າຂອງທີ່ດິນ',
    'titleNo': 'ເລກໃບຕາດິນ',
    'year': 'ປີ',
    'amountDue': 'ຄ່າທີ່ຕ້ອງຊຳລະ',
    'amountPaid': 'ຊຳລະແລ້ວ',
    'penalty': 'ຄ່າປັບ',
    'outstanding': 'ຄ້າງຊຳລະ',
    'dueDate': 'ກຳນົດຊຳລະ',
    'status': 'ສະຖານະ',
    'viewFee': 'ເບິ່ງຄ່າຊຳລະ',
    'payNow': 'ຊຳລະເງິນ',
    'addToMyParcels': 'ເພີ່ມໃສ່ຕອນດິນຂອງຂ້ອຍ',
    'inMyParcels': 'ຢູ່ໃນຕອນດິນຂອງຂ້ອຍແລ້ວ',
    'claimPhoneMismatch':
        'ເບີໂທທີ່ບັນທຶກໄວ້ໃນຕອນດິນນີ້ ບໍ່ກົງກັບເບີໂທຂອງທ່ານ — ກະລຸນາຕິດຕໍ່ຫ້ອງການທີ່ດິນເມືອງ.',
    'claimOwnedByOther':
        'ຕອນດິນນີ້ ເຊື່ອມກັບບັນຊີອື່ນແລ້ວ — ກະລຸນາຕິດຕໍ່ຫ້ອງການທີ່ດິນເມືອງ.',
    'claimNoOwner':
        'ຕອນດິນນີ້ ຍັງບໍ່ມີຊື່ເຈົ້າຂອງໃນລະບົບ — ກະລຸນາຕິດຕໍ່ຫ້ອງການທີ່ດິນເມືອງ.',
    'paymentSlip': 'ໃບສະລິບຊຳລະເງິນ',
    'reference': 'ລະຫັດອ້າງອີງ',
    'uploadSlip': 'ອັບໂຫລດສະລິບເງິນ',
    'uploadSlipHint': 'ຖ່າຍຮູບສະລິບເງິນທີ່ທ່ານໄດ້ຊຳລະໃນທະນາຄານ',
    'takePhoto': 'ຖ່າຍຮູບ',
    'chooseGallery': 'ເລືອກຈາກຄັງຮູບ',
    'photoPermission':
        'ບໍ່ສາມາດເປີດກ້ອງ ຫຼື ຮູບພາບໄດ້. ກະລຸນາອະນຸຍາດໃຫ້ແອັບເຂົ້າເຖິງກ້ອງ ແລ້ວລອງໃໝ່.',
    'submit': 'ສົ່ງ',
    'cancel': 'ຍົກເລີກ',
    'method': 'ວິທີຊຳລະ',
    'methodBank': 'ໂອນຜ່ານທະນາຄານ',
    'methodMobile': 'ຜ່ານທະນາຄານມືຖື',
    'methodCounter': 'ຈ່າຍຢູ່ຫ້ອງການ',
    'fullAmount': 'ຊຳລະເຕັມ',
    'partialAmount': 'ຊຳລະບາງສ່ວນ',
    'totalOutstanding': 'ຄ້າງຊຳລະລວມ',
    'noParcels': 'ບໍ່ມີຕອນດິນໃນຊື່ທ່ານ',
    'noParcelsHint':
        'ຕອນດິນຈະປາກົດຢູ່ນີ້ ຫຼັງຈາກທ່ານຄົ້ນຫາ ແລະ ເພີ່ມໃສ່ຕອນດິນຂອງຂ້ອຍ.',
    'noPayments': 'ຍັງບໍ່ມີການຊຳລະ',
    'loading': 'ກຳລັງໂຫຼດ...',
    'retry': 'ລອງໃໝ່',
    'banksError':
        'ໂຫຼດລາຍຊື່ທະນາຄານບໍ່ໄດ້ — ກະລຸນາກວດເບິ່ງການເຊື່ອມຕໍ່ ແລ້ວລອງຄືນ',
    'refresh': 'ປັບໃໝ່',
    'statusUnpaid': 'ຍັງບໍ່ໄດ້ຊຳລະ',
    'statusPartial': 'ຊຳລະບາງສ່ວນ',
    'statusPaid': 'ຊຳລະແລ້ວ',
    'statusOverpaid': 'ຊຳລະເກີນ',
    'statusWaived': 'ຍົກເວັ້ນ',
    'statusIssued': 'ລໍຖ້າຊຳລະ',
    'statusSubmitted': 'ສົ່ງຫຼັກຖານແລ້ວ',
    'statusRejected': 'ຖືກປະຕິເສດ',
    'sendToDistrict': 'ສົ່ງໃຫ້ເມືອງ',
    'submittedToDistrict': 'ສົ່ງສະລິບໄປຍັງເມືອງແລ້ວ — ລໍຖ້າພະນັກງານກວດສອບ',
    'statusPending': 'ກຳລັງກວດສອບ',
    'confirmUpload': 'ຍືນຢັນການອັບໂຫລດ',
    'openInBrowser': 'ເປີດໃນ browser',
    'noBrowser': 'ບໍ່ສາມາດເປີດ browser ໄດ້',
    'language': 'ພາສາ',
    'version': 'ເວີຊັນ',
    'saved': 'ບັນທຶກແລ້ວ',
    'noData': 'ບໍ່ມີຂໍ້ມູນ',
  };

  static const Map<String, String> _en = <String, String>{
    'appTitle': 'Land Tax Payment',
    'appSubtitle': 'Citizen land services',
    'signIn': 'Sign in',
    'signOut': 'Sign out',
    'phone': 'Phone number',
    'createAccount': 'Create an account',
    'findParcel': 'Find a parcel',
    'myParcels': 'My parcels',
    'myPayments': 'My payments',
    'myAccount': 'My account',
    'about': 'About',
    'parcelNo': 'Parcel no.',
    'mapSheet': 'Map sheet',
    'province': 'Province',
    'enterParcelIdentity':
        'Choose the province, district and village, then enter the map sheet and parcel number',
    'parcelNotFound':
        'No parcel with that number — check the map sheet and parcel number against your title',
    'ambiguousParcel':
        'That parcel number matches more than one parcel — contact the district land office',
    'district': 'District',
    'village': 'Village',
    'area': 'Area',
    'zone': 'Zone',
    'owner': 'Owner',
    'titleNo': 'Title no.',
    'year': 'Year',
    'amountDue': 'Amount due',
    'amountPaid': 'Paid',
    'penalty': 'Penalty',
    'outstanding': 'Outstanding',
    'dueDate': 'Due date',
    'status': 'Status',
    'viewFee': 'View fee',
    'payNow': 'Pay now',
    'addToMyParcels': 'Add to my parcels',
    'inMyParcels': 'In my parcels',
    'claimPhoneMismatch':
        'The phone number recorded on this parcel does not match yours — contact the district land office.',
    'claimOwnedByOther':
        'This parcel is already linked to another account — contact the district land office.',
    'claimNoOwner':
        'This parcel has no owner recorded — contact the district land office.',
    'paymentSlip': 'Payment slip',
    'reference': 'Reference',
    'uploadSlip': 'Upload payment slip',
    'uploadSlipHint': 'Photo of the bank slip you paid with',
    'takePhoto': 'Take photo',
    'chooseGallery': 'Choose from gallery',
    'photoPermission':
        'Could not open the camera or gallery. Allow photo access for the app and try again.',
    'submit': 'Submit',
    'cancel': 'Cancel',
    'method': 'Payment method',
    'methodBank': 'Bank transfer',
    'methodMobile': 'Mobile money',
    'methodCounter': 'Pay at the counter',
    'fullAmount': 'Pay in full',
    'partialAmount': 'Pay part',
    'totalOutstanding': 'Total outstanding',
    'noParcels': 'No parcels on your account',
    'noParcelsHint':
        'Your parcels appear here after you look one up and add it to My parcels.',
    'noPayments': 'No payments yet',
    'loading': 'Loading...',
    'retry': 'Retry',
    'banksError':
        'Could not load the bank list. Check your connection and try again.',
    'refresh': 'Refresh',
    'statusUnpaid': 'Unpaid',
    'statusPartial': 'Partly paid',
    'statusPaid': 'Paid',
    'statusOverpaid': 'Overpaid',
    'statusWaived': 'Waived',
    'statusIssued': 'Awaiting payment',
    'statusSubmitted': 'Submitted for review',
    'statusRejected': 'Rejected',
    'sendToDistrict': 'Send to the district',
    'submittedToDistrict':
        'Slip sent to the district — waiting for an officer to verify it',
    'statusPending': 'Being checked',
    'confirmUpload': 'Confirm upload',
    'openInBrowser': 'Open in browser',
    'noBrowser': 'Could not open a browser',
    'language': 'Language',
    'version': 'Version',
    'saved': 'Saved',
    'noData': 'No data',
  };
}
