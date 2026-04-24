-- =========================================================
-- RADOG'S KENNEL PET HOTEL MANAGEMENT SYSTEM
-- SCRIPT A: DDL (TABLE & CONSTRAINT CREATION)
-- RDBMS: ORACLE
--TEST
-- =========================================================

-- 1. EMPLOYEE TABLE
CREATE TABLE EMPLOYEE (
    Employee_ID NUMBER(10) PRIMARY KEY,
    Employee_Username VARCHAR2(50) UNIQUE NOT NULL,
    Password_Hash VARCHAR2(255) NOT NULL
);

-- 2. USER_GROUP TABLE
CREATE TABLE USER_GROUP (
    User_Group_ID NUMBER(10) PRIMARY KEY,
    Group_Name VARCHAR2(50) UNIQUE NOT NULL
);

-- 3. USER_ACCOUNT TABLE
CREATE TABLE USER_ACCOUNT (
    Account_ID NUMBER(10) PRIMARY KEY,
    Username VARCHAR2(50) UNIQUE NOT NULL,
    Account_Status VARCHAR2(50) NOT NULL,
    Password_Hash VARCHAR2(255) NOT NULL,
    Employee_ID NUMBER(10) NOT NULL,
    User_Group_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_acc_employee FOREIGN KEY (Employee_ID) REFERENCES EMPLOYEE (Employee_ID),
    CONSTRAINT fk_acc_usrgrp FOREIGN KEY (User_Group_ID) REFERENCES USER_GROUP (User_Group_ID),
    CONSTRAINT chk_acc_status CHECK (Account_Status IN ('Active', 'Inactive'))
);

-- 4. USER_PRIVILEGE TABLE
CREATE TABLE USER_PRIVILEGE (
    Privilege_ID NUMBER(10) PRIMARY KEY,
    Privilege_Name VARCHAR2(50) NOT NULL,
    User_Group_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_priv_usrgrp FOREIGN KEY (User_Group_ID) REFERENCES USER_GROUP (User_Group_ID)
);

-- 5. OWNER TABLE
CREATE TABLE OWNER (
    Owner_ID NUMBER(10) PRIMARY KEY,
    First_Name VARCHAR2(100) NOT NULL,
    Last_Name VARCHAR2(100) NOT NULL,
    Contact_Number VARCHAR2(20) UNIQUE NOT NULL
);

-- 6. PET_CATEGORY TABLE
CREATE TABLE PET_CATEGORY (
    Category_ID NUMBER(10) PRIMARY KEY,
    Category_Name VARCHAR2(50) UNIQUE NOT NULL,
    Species_Notes VARCHAR2(255)
);

-- 7. PET TABLE
CREATE TABLE PET (
    Pet_ID NUMBER(10) PRIMARY KEY,
    Pet_Name VARCHAR2(50) NOT NULL,
    Sex VARCHAR2(10),
    Weight NUMBER(5,2),
    Feeding_Time VARCHAR2(20),
    Feeding_Portion VARCHAR2(50),
    Behavioral_Notes VARCHAR2(255),
    Owner_ID NUMBER(10) NOT NULL,
    Category_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_pet_owner FOREIGN KEY (Owner_ID) REFERENCES OWNER (Owner_ID),
    CONSTRAINT fk_pet_category FOREIGN KEY (Category_ID) REFERENCES PET_CATEGORY (Category_ID),
    CONSTRAINT chk_pet_sex CHECK (Sex IN ('Male', 'Female')),
    CONSTRAINT chk_pet_weight CHECK (Weight > 0.00)
);

-- 8. PET_DOCUMENT TABLE
CREATE TABLE PET_DOCUMENT (
    Doc_ID NUMBER(10) PRIMARY KEY,
    Document_Type VARCHAR2(50),
    Filepath VARCHAR2(255) UNIQUE NOT NULL,
    Upload_Date DATE DEFAULT SYSDATE,
    Pet_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_doc_pet FOREIGN KEY (Pet_ID) REFERENCES PET (Pet_ID)
);

-- 9. TIER TABLE
CREATE TABLE TIER (
    Tier_ID NUMBER(10) PRIMARY KEY,
    Tier_Name VARCHAR2(20) UNIQUE NOT NULL,
    Tier_Description VARCHAR2(255),
    Weight_Min NUMBER(10, 2) NOT NULL,
    Weight_Max NUMBER(10, 2) NOT NULL,
    CONSTRAINT chk_tier_name CHECK (Tier_Name IN ('Small', 'Medium', 'Large', 'Giant')),
    CONSTRAINT chk_tier_w_min CHECK (Weight_Min >= 0.00),
    CONSTRAINT chk_tier_w_max CHECK (Weight_Max > Weight_Min)
);

-- 10. ACCOMMODATION TABLE
CREATE TABLE ACCOMMODATION (
    Accommodation_ID NUMBER(10) PRIMARY KEY,
    Unit_Name VARCHAR2(50) UNIQUE NOT NULL,
    Accommodation_Type VARCHAR2(50) NOT NULL,
    Daily_Cost NUMBER(10,2) NOT NULL,
    Occupancy_Status VARCHAR2(20) NOT NULL,
    Tier_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_acc_tier FOREIGN KEY (Tier_ID) REFERENCES TIER(Tier_ID),
    CONSTRAINT chk_acc_cost CHECK (Daily_Cost >= 0.00),
    CONSTRAINT chk_occ_status CHECK (Occupancy_Status IN ('Available', 'Booked', 'Under Maintenance'))
);

-- 11. SERVICE TABLE
CREATE TABLE SERVICE (
    Service_ID NUMBER(10) PRIMARY KEY,
    Service_Name VARCHAR2(100) UNIQUE NOT NULL,
    Service_Description VARCHAR2(255),
    Price NUMBER(10,2) NOT NULL,
    Tier_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_svc_tier FOREIGN KEY (Tier_ID) REFERENCES TIER(Tier_ID),
    CONSTRAINT chk_svc_price CHECK (Price >= 0.00)
);

-- 12. BOOKING TABLE
CREATE TABLE BOOKING (
    Booking_ID NUMBER (10) PRIMARY KEY,
    Booking_Status VARCHAR2(20) NOT NULL,
    Check_In_Date DATE NOT NULL,
    Check_Out_Date DATE NOT NULL,
    Consent_Form_Signed VARCHAR2(5),
    Reg_Form_Verified VARCHAR2(5),
    Waiver_Verified VARCHAR2(5),
    Vacc_Card_Verified VARCHAR2(5),
    Special_Instructions VARCHAR2(255),
    Owner_ID NUMBER (10) NOT NULL,
    Pet_ID NUMBER (10) NOT NULL,
    Employee_ID NUMBER (10) NOT NULL, 
   CONSTRAINT fk_bk_owner FOREIGN KEY (Owner_ID) REFERENCES OWNER (Owner_ID),
    CONSTRAINT fk_bk_pet FOREIGN KEY (Pet_ID) REFERENCES PET(Pet_ID),
    CONSTRAINT fk_bk_employee FOREIGN KEY (Employee_ID) REFERENCES EMPLOYEE (Employee_ID),
    CONSTRAINT chk_bk_status CHECK (Booking_Status IN ('Confirmed', 'Pending', 'Cancelled', 'Completed')),
    CONSTRAINT chk_bk_dates CHECK (Check_Out_Date > Check_In_Date)
);


-- 13. SERVICE_DETAILS TABLE
CREATE TABLE SERVICE_DETAILS (
    Booking_Service_ID NUMBER(10) PRIMARY KEY,
    Service_Choice VARCHAR2(100),
    Service_ID NUMBER(10) NOT NULL,
    Booking_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_svcdet_svc FOREIGN KEY (Service_ID) REFERENCES SERVICE (Service_ID),
  CONSTRAINT fk_svcdet_bk FOREIGN KEY (Booking_ID) REFERENCES BOOKING (Booking_ID)
);

-- 14. PAYMENT_METHOD TABLE
CREATE TABLE PAYMENT_METHOD (
    Payment_Method_ID NUMBER(10) PRIMARY KEY,
    Method_Name VARCHAR2(50) UNIQUE NOT NULL,
    Method_Status VARCHAR2(10) NOT NULL,
    CONSTRAINT chk_paymeth_status CHECK (Method_Status IN ('Active', 'Inactive'))
);

-- 15. PAYMENT TABLE
CREATE TABLE PAYMENT (
    Payment_ID NUMBER(10) PRIMARY KEY,
    Payment_Status VARCHAR2(20) NOT NULL,
    Total_Amount NUMBER(10,2) NOT NULL,
    Booking_ID NUMBER(10) NOT NULL,
    Payment_Method_ID NUMBER(10) NOT NULL,
    CONSTRAINT fk_pay_bk FOREIGN KEY (Booking_ID) REFERENCES BOOKING (Booking_ID),
    CONSTRAINT fk_pay_method FOREIGN KEY (Payment_Method_ID) REFERENCES PAYMENT_METHOD (Payment_Method_ID),
    CONSTRAINT chk_pay_status CHECK (Payment_Status IN ('Paid', 'Pending', 'Cancelled')),
    CONSTRAINT chk_pay_amount CHECK (Total_Amount >= 0.00)
);

COMMIT;

