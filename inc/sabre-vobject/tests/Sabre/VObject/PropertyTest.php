<?php

namespace Sabre\VObject;

use
    Sabre\VObject\Component\VCalendar;

class PropertyTest extends \PHPUnit_Framework_TestCase {

    public function testToString() {

        $cal = new VCalendar();

        $property = $cal->createProperty('propname','propvalue');
        $this->assertEquals('PROPNAME', $property->name);
        $this->assertEquals('propvalue', $property->__toString());
        $this->assertEquals('propvalue', (string)$property);
        $this->assertEquals('propvalue', $property->getValue());

    }

    public function testCreate() {

        $cal = new VCalendar();

        $params = array(
            'param1' => 'value1',
            'param2' => 'value2',
        );

        $property = $cal->createProperty('propname','propvalue', $params);

        $this->assertEquals('value1', $property['param1']->getValue());
        $this->assertEquals('value2', $property['param2']->getValue());

    }

    public function testSetValue() {

        $cal = new VCalendar();

        $property = $cal->createProperty('propname','propvalue');
        $property->setValue('value2');

        $this->assertEquals('PROPNAME', $property->name);
        $this->assertEquals('value2', $property->__toString());

    }

    public function testParameterExists() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');
        $property['paramname'] = 'paramvalue';

        $this->assertTrue(isset($property['PARAMNAME']));
        $this->assertTrue(isset($property['paramname']));
        $this->assertFalse(isset($property['foo']));

    }

    public function testParameterGet() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');
        $property['paramname'] = 'paramvalue';

        $this->assertInstanceOf('Sabre\\VObject\\Parameter',$property['paramname']);

    }

    public function testParameterNotExists() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');
        $property['paramname'] = 'paramvalue';

        $this->assertInternalType('null',$property['foo']);

    }

    public function testParameterMultiple() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');
        $property['paramname'] = 'paramvalue';
        $property->add('paramname', 'paramvalue');

        $this->assertInstanceOf('Sabre\\VObject\\Parameter',$property['paramname']);
        $this->assertEquals(2,count($property['paramname']->getParts()));

    }

    public function testSetParameterAsString() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');
        $property['paramname'] = 'paramvalue';

        $this->assertEquals(1,count($property->parameters()));
        $this->assertInstanceOf('Sabre\\VObject\\Parameter', $property->parameters['PARAMNAME']);
        $this->assertEquals('PARAMNAME',$property->parameters['PARAMNAME']->name);
        $this->assertEquals('paramvalue',$property->parameters['PARAMNAME']->getValue());

    }

    public function testUnsetParameter() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');
        $property['paramname'] = 'paramvalue';

        unset($property['PARAMNAME']);
        $this->assertEquals(0,count($property->parameters()));

    }

    public function testSerialize() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');

        $this->assertEquals("PROPNAME:propvalue\r\n",$property->serialize());

    }

    public function testSerializeParam() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue', array(
            'paramname' => 'paramvalue',
            'paramname2' => 'paramvalue2',
        ));

        $this->assertEquals("PROPNAME;PARAMNAME=paramvalue;PARAMNAME2=paramvalue2:propvalue\r\n",$property->serialize());

    }

    public function testSerializeNewLine() {

        $cal = new VCalendar();
        $property = $cal->createProperty('SUMMARY',"line1\nline2");

        $this->assertEquals("SUMMARY:line1\\nline2\r\n",$property->serialize());

    }

    public function testSerializeLongLine() {

        $cal = new VCalendar();
        $value = str_repeat('!',200);
        $property = $cal->createProperty('propname',$value);

        $expected = "PROPNAME:" . str_repeat('!',66) . "\r\n " . str_repeat('!',74) . "\r\n " . str_repeat('!',60) . "\r\n";

        $this->assertEquals($expected,$property->serialize());

    }

    public function testSerializeUTF8LineFold() {

        $cal = new VCalendar();
        $value = str_repeat('!',65) . "\xc3\xa4bla"; // inserted umlaut-a
        $property = $cal->createProperty('propname', $value);
        $expected = "PROPNAME:" . str_repeat('!',65) . "\r\n \xc3\xa4bla\r\n";
        $this->assertEquals($expected, $property->serialize());

    }

    public function testGetIterator() {

        $cal = new VCalendar();
        $it = new ElementList(array());
        $property = $cal->createProperty('propname','propvalue');
        $property->setIterator($it);
        $this->assertEquals($it,$property->getIterator());

    }


    public function testGetIteratorDefault() {

        $cal = new VCalendar();
        $property = $cal->createProperty('propname','propvalue');
        $it = $property->getIterator();
        $this->assertTrue($it instanceof ElementList);
        $this->assertEquals(1,count($it));

    }

    function testAddScalar() {

        $cal = new VCalendar();
        $property = $cal->createProperty('EMAIL');

        $property->add('myparam','value');

        $this->assertEquals(1, count($property->parameters()));

        $this->assertTrue($property->parameters['MYPARAM'] instanceof Parameter);
        $this->assertEquals('MYPARAM',$property->parameters['MYPARAM']->name);
        $this->assertEquals('value',$property->parameters['MYPARAM']->getValue());

    }

    function testAddParameter() {

        $cal = new VCalendar();
        $prop = $cal->createProperty('EMAIL');

        $prop->add('MYPARAM','value');

        $this->assertEquals(1, count($prop->parameters()));
        $this->assertEquals('MYPARAM',$prop['myparam']->name);

    }

    function testAddParameterTwice() {

        $cal = new VCalendar();
        $prop = $cal->createProperty('EMAIL');

        $prop->add('MYPARAM', 'value1');
        $prop->add('MYPARAM', 'value2');

        $this->assertEquals(1, count($prop->parameters));
        $this->assertEquals(2, count($prop->parameters['MYPARAM']->getParts()));

        $this->assertEquals('MYPARAM',$prop['MYPARAM']->name);

    }


    function testClone() {

        $cal = new VCalendar();
        $property = $cal->createProperty('EMAIL','value');
        $property['FOO'] = 'BAR';

        $property2 = clone $property;

        $property['FOO'] = 'BAZ';
        $this->assertEquals('BAR', (string)$property2['FOO']);

    }

    function testCreateParams() {

        $cal = new VCalendar();
        $property = $cal->createProperty('X-PROP','value', array(
            'param1' => 'value1',
            'param2' => array('value2', 'value3')
        ));

        $this->assertEquals(1, count($property['PARAM1']->getParts()));
        $this->assertEquals(2, count($property['PARAM2']->getParts()));

    }

    function testValidateNonUTF8() {

        $calendar = new VCalendar();
        $property = $calendar->createProperty('X-PROP', "Bla\x00");
        $result = $property->validate(Property::REPAIR);

        $this->assertEquals('Property is not valid UTF-8!', $result[0]['message']);
        $this->assertEquals('Bla', $property->getValue());

    }


    function testValidateBadPropertyName() {

        $calendar = new VCalendar();
        $property = $calendar->createProperty("X_*&PROP*", "Bla");
        $result = $property->validate(Property::REPAIR);

        $this->assertEquals($result[0]['message'], 'The propertyname: X_*&PROP* contains invalid characters. Only A-Z, 0-9 and - are allowed');
        $this->assertEquals('X-PROP', $property->name);

    }

    function testGetValue() {

        $calendar = new VCalendar();
        $property = $calendar->createProperty("SUMMARY", null);
        $this->assertEquals(array(), $property->getParts());
        $this->assertNull($property->getValue());

        $property->setValue(array());
        $this->assertEquals(array(), $property->getParts());
        $this->assertNull($property->getValue());

        $property->setValue(array(1));
        $this->assertEquals(array(1), $property->getParts());
        $this->assertEquals(1, $property->getValue());

        $property->setValue(array(1,2));
        $this->assertEquals(array(1,2), $property->getParts());
        $this->assertEquals('1,2', $property->getValue());

        $property->setValue('str');
        $this->assertEquals(array('str'), $property->getParts());
        $this->assertEquals('str', $property->getValue());
    }

    /**
     * ElementList should reject this.
     *
     * @expectedException \LogicException
     */
    public function testArrayAccessSetInt() {

        $calendar = new VCalendar();
        $property = $calendar->createProperty("X-PROP", null);

        $calendar->add($property);
        $calendar->{'X-PROP'}[0] = 'Something!';

    }

    /**
     * ElementList should reject this.
     *
     * @expectedException \LogicException
     */
    public function testArrayAccessUnsetInt() {

        $calendar = new VCalendar();
        $property = $calendar->createProperty("X-PROP", null);

        $calendar->add($property);
        unset($calendar->{'X-PROP'}[0]);

    }

}
